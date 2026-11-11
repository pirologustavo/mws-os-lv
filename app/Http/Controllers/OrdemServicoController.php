<?php

namespace App\Http\Controllers;

use App\Interface\OrdemServicoInterface;
use App\Mail\OrcamentoEmDiagnostico;
use App\Mail\OrcamentoFinalizado;
use App\Models\OrdemServico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Interface\OsStatusInterface;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrcamentoAguardandoAprovacao;
use App\Mail\OrcamentoAprovado;
use App\Http\Service\ServicoInterno;
use App\Support\DogStatsD;

class OrdemServicoController extends Controller
{
    public function publicoAssinado(Request $request, string $id)
    {
        $secret = (string) config('services.os_approval_secret');
        $signature = (string) $request->query('signature', '');
        abort_if($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $id, $secret), $signature), 403, 'Assinatura inválida.');
        $os = OrdemServico::where('osid', $id)->first();
        abort_if(!$os, 404, 'OS não encontrada.');
        return response()->json([
            'os' => [
                'osid' => $os->osid, 'nome_cliente' => $os->cliente_nome_snapshot,
                'modelo' => $os->veiculo_modelo_snapshot, 'sintomas' => $os->sintomas,
                'analise' => $os->analise, 'valor_total' => $os->valor_total,
            ],
            'itens' => $os->itens()->get()->map(fn ($item) => [
                'id' => $item->estoqid, 'descricao' => $item->descricao_snapshot,
                'qtd' => $item->quantidade, 'preco' => $item->valor_unitario,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'clientid'  => 'required|integer',
            'carid'   => 'required|integer',
            'funcid'   => 'required|integer',
            'sintomas'  => 'nullable|string',
            'itens'     => 'required|array|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $servicos = app(ServicoInterno::class);
        $itensEntrada = collect($request->itens)->map(fn ($item) => is_array($item) ? $item : json_decode($item, true));
        if ($itensEntrada->contains(fn ($item) => !is_array($item) || empty($item['id']) || !is_numeric($item['qtd'] ?? null) || (int) $item['qtd'] <= 0 || (float) $item['qtd'] !== (float) (int) $item['qtd'])) {
            return response()->json(['message' => 'Itens inválidos: informe o ID e uma quantidade positiva.'], 422);
        }
        $itensEntrada = $itensEntrada->groupBy('id')->map(fn ($group, $itemId) => [
            'id' => (int) $itemId, 'qtd' => $group->sum('qtd'),
        ])->values();
        $clientes = $servicos->consultar('cliente', '/api/internal/v1/clientes', ['ids' => [$request->clientid]]);
        $veiculos = $servicos->consultar('veiculo', '/api/internal/v1/veiculos', ['ids' => [$request->carid]]);
        $funcionarios = $servicos->consultar('funcionario', '/api/internal/v1/funcionarios', ['ids' => [$request->funcid]]);
        $idsEstoque = $itensEntrada->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $itensEstoque = $servicos->consultar('estoque', '/api/internal/v1/itens-estoque', ['ids' => $idsEstoque]);
        $cliente = $clientes[0] ?? null;
        $veiculo = $veiculos[0] ?? null;
        $funcionario = $funcionarios[0] ?? null;
        if (!$cliente || !$veiculo || (int) $veiculo['clientid'] !== (int) $request->clientid || !$funcionario || count($itensEstoque) !== count($idsEstoque)) {
            return response()->json(['message' => 'Uma ou mais referências de cliente, veículo, mecânico ou estoque são inválidas.'], 422);
        }
        $estoquePorId = collect($itensEstoque)->keyBy('estoqid');
        $valorTotal = 0;
        foreach ($itensEntrada as $item) {
            $itemEstoque = $estoquePorId[$item['id']] ?? null;
            if (!$itemEstoque || ($itemEstoque['tipo'] === 'peca' && (int) $itemEstoque['quantidade_atual'] < (int) $item['qtd'])) {
                return response()->json(['message' => 'Item de estoque indisponível na quantidade solicitada.'], 409);
            }
            $valorTotal += (float) $itemEstoque['valor_venda'] * (int) $item['qtd'];
        }

        $osid = DB::transaction(function () use ($request, $cliente, $veiculo, $funcionario, $itensEntrada, $estoquePorId, $valorTotal) {

            $os = OrdemServico::create([
                'clientid'  => $request->clientid,
                'carid'     => $request->carid,
                'funcid'    => $request->funcid,
                'sintomas'  => $request->sintomas,
                'valor_total' => $valorTotal,
                'status_atual' => 'Recebida',
                'statid_atual' => 1,
                'cliente_nome_snapshot' => $cliente['nome'],
                'cliente_email_snapshot' => $cliente['email'] ?? null,
                'veiculo_modelo_snapshot' => $veiculo['modelo'],
                'veiculo_placa_snapshot' => $veiculo['placa'],
                'funcionario_nome_snapshot' => $funcionario['nome']
            ]);

            foreach ($itensEntrada as $item) {
                $itemEstoque = $estoquePorId[$item['id']] ?? null;
                if (!$itemEstoque) abort(422, 'Item de estoque indisponível.');
                $preco = $itemEstoque['valor_venda'];

                $os->itens()->create([
                    'estoqid'        => $item['id'],
                    'quantidade'     => $item['qtd'],
                    'valor_unitario' => $preco,
                    'subtotal'       => $item['qtd'] * $preco,
                    'descricao_snapshot' => $itemEstoque['descricao']
                ]);
            }

            $os->historicoStatus()->create([
                'statid' => 1
            ]);

            return $os->osid;
        });

        $idempotencyKey = (string) \Illuminate\Support\Str::uuid();
        try {
            $servicos->ajustarEstoque($itensEntrada->map(fn ($item) => [
                'id' => (int) $item['id'], 'qtd' => (int) $item['qtd'],
            ])->all(), $idempotencyKey);
        } catch (\Throwable $exception) {
            DogStatsD::metric('mws.os.processing.failures', 1, 'c', ['service' => 'os-lv', 'operation' => 'create']);
            DB::transaction(function () use ($osid) {
                $os = OrdemServico::where('osid', $osid)->first();
                if ($os) {
                    $os->itens()->delete();
                    $os->historicoStatus()->delete();
                    $os->delete();
                }
            });
            throw $exception;
        }

        return response()->json(['message' => 'Ordem de Serviço criada com sucesso!', 'osid' => $osid], 201);
    }

    public function listar()
    {
        $os = $this->listarComSnapshots(OrdemServico::query());
        return response()->json($os);
    }

    public function buscarPorId(string $id)
    {
        $os = OrdemServico::where('osid', $id)->first();

        if (!$os) {
            return response()->json(['message' => 'OS não encontrada'], 404);
        }

        $itens = $os->itens()->get()->map(fn ($item) => [
            'id' => $item->estoqid, 'descricao' => $item->descricao_snapshot,
            'qtd' => $item->quantidade, 'preco' => $item->valor_unitario,
        ]);
        $os->nome_cliente = $os->cliente_nome_snapshot;
        $os->modelo = $os->veiculo_modelo_snapshot;
        $os->placa = $os->veiculo_placa_snapshot;
        $os->nome_funcionario = $os->funcionario_nome_snapshot;

        return response()->json([
            'os' => $os,
            'itens' => $itens
        ]);
    }

    public function atualizarOs(Request $request, $id)
    {
        $os = OrdemServico::where('osid', $id)->first();

        if (!$os) {
            return response()->json(['message' => "Ordem de Serviço $id não encontrada"], 404);
        }

        $clientId = (int) $request->input('clientid', $os->clientid);
        $carId = (int) $request->input('carid', $os->carid);
        $employeeId = (int) $request->input('funcid', $os->funcid);
        $services = app(ServicoInterno::class);
        $client = $services->consultar('cliente', '/api/internal/v1/clientes', ['ids' => [$clientId]])[0] ?? null;
        $vehicle = $services->consultar('veiculo', '/api/internal/v1/veiculos', ['ids' => [$carId]])[0] ?? null;
        $employee = $services->consultar('funcionario', '/api/internal/v1/funcionarios', ['ids' => [$employeeId]])[0] ?? null;
        if (!$client || !$vehicle || (int) $vehicle['clientid'] !== $clientId || !$employee) {
            return response()->json(['message' => 'Referências de cliente, veículo ou mecânico inválidas.'], 422);
        }
        $itensEnviados = $request->has('itens')
            ? collect($request->itens)->map(fn ($item) => is_array($item) ? $item : json_decode($item, true))
            : collect();
        if ($request->has('itens') && $itensEnviados->isEmpty()) {
            return response()->json(['message' => 'A OS precisa conter ao menos um item.'], 422);
        }
        if ($itensEnviados->contains(fn ($item) => !is_array($item) || empty($item['id']) || !is_numeric($item['qtd'] ?? null) || (int) $item['qtd'] <= 0 || (float) $item['qtd'] !== (float) (int) $item['qtd'])) {
            return response()->json(['message' => 'Itens inválidos.'], 422);
        }
        if ($request->has('itens')) {
            $itensEnviados = $itensEnviados->groupBy('id')->map(fn ($group, $itemId) => [
                'id' => (int) $itemId, 'qtd' => $group->sum('qtd'),
            ])->values();
        }
        $stockById = collect();
        $total = $os->valor_total;
        if ($request->has('itens')) {
            $stockIds = $itensEnviados->pluck('id')->map(fn ($itemId) => (int) $itemId)->unique()->values()->all();
            $stockItems = $services->consultar('estoque', '/api/internal/v1/itens-estoque', ['ids' => $stockIds, 'include_archived' => true]);
            if (count($stockItems) !== count($stockIds)) return response()->json(['message' => 'Item de estoque inválido ou arquivado.'], 422);
            $stockById = collect($stockItems)->keyBy('estoqid');
            $total = 0;
            foreach ($itensEnviados as $item) {
                $stock = $stockById[$item['id']] ?? null;
                if (!$stock) return response()->json(['message' => 'Item de estoque não encontrado.'], 422);
                $total += (float) $stock['valor_venda'] * (int) $item['qtd'];
            }
        }

        $currentById = $os->itens()->get()->groupBy('estoqid')->map(fn ($group) => $group->sum('quantidade'));
        $desiredById = $request->has('itens')
            ? $itensEnviados->groupBy('id')->map(fn ($group) => $group->sum('qtd'))
            : $currentById;
        $adjustments = [];
        foreach ($currentById->keys()->merge($desiredById->keys())->unique() as $stockId) {
            $difference = (int) $desiredById->get($stockId, 0) - (int) $currentById->get($stockId, 0);
            if ($difference === 0) continue;
            $stock = $stockById[$stockId] ?? null;
            if ($difference > 0 && (!$stock || !$stock['ativo'] || ($stock['tipo'] === 'peca' && (int) $stock['quantidade_atual'] < $difference))) {
                return response()->json(['message' => 'Saldo de estoque insuficiente ou item arquivado.'], 409);
            }
            $adjustments[] = ['id' => (int) $stockId, 'qtd' => $difference];
        }

        $stockAdjustmentApplied = false;
        if ($adjustments) {
            $services->ajustarEstoque($adjustments, (string) \Illuminate\Support\Str::uuid());
            $stockAdjustmentApplied = true;
        }

        try {
        DB::transaction(function () use ($os, $request, $id, $clientId, $carId, $employeeId, $total, $client, $vehicle, $employee, $itensEnviados, $stockById) {
        $os->update([
            'clientid'     => $clientId,
            'carid'        => $carId,
            'sintomas'     => $request->input('sintomas'),
            'funcid'       => $employeeId,
            'valor_total'  => $total,
            'cliente_nome_snapshot' => $client['nome'],
            'cliente_email_snapshot' => $client['email'] ?? null,
            'veiculo_modelo_snapshot' => $vehicle['modelo'],
            'veiculo_placa_snapshot' => $vehicle['placa'],
            'funcionario_nome_snapshot' => $employee['nome'],
        ]);

        if ($request->has('itens')) {
            $idsEnviados = [];

            foreach ($itensEnviados as $item) {
                $estoqid = $item['id'];
                $idsEnviados[] = $estoqid;
                $preco = $stockById[$estoqid]['valor_venda'];
                $descricao = $stockById[$estoqid]['descricao'];

                $itemExistente = DB::table('os_itens')
                    ->where('osid', $id)
                    ->where('estoqid', $estoqid)
                    ->first();

                if ($itemExistente) {
                    DB::table('os_itens')
                        ->where('osid', $id)
                        ->where('estoqid', $estoqid)
                        ->update([
                            'quantidade'     => $item['qtd'],
                            'valor_unitario' => $preco,
                            'subtotal'       => $item['qtd'] * $preco,
                            'descricao_snapshot' => $descricao,
                            'updated_at'     => now()
                        ]);
                } else {
                    DB::table('os_itens')->insert([
                        'osid'           => $id,
                        'estoqid'        => $estoqid,
                        'quantidade'     => $item['qtd'],
                        'valor_unitario' => $preco,
                        'subtotal'       => $item['qtd'] * $preco,
                        'descricao_snapshot' => $descricao,
                        'created_at'     => now(),
                        'updated_at'     => now()
                    ]);
                }
            }

            DB::table('os_itens')
                ->where('osid', $id)
                ->whereNotIn('estoqid', $idsEnviados)
                ->delete();
        }
        });
        } catch (\Throwable $exception) {
            DogStatsD::metric('mws.os.processing.failures', 1, 'c', ['service' => 'os-lv', 'operation' => 'update']);
            if ($stockAdjustmentApplied) {
                try {
                    $services->ajustarEstoque(array_map(fn ($item) => ['id' => $item['id'], 'qtd' => -$item['qtd']], $adjustments), (string) \Illuminate\Support\Str::uuid());
                } catch (\Throwable $compensationFailure) {
                    \Illuminate\Support\Facades\Log::error('Falha ao compensar ajuste de estoque da OS ' . $id, [
                        'correlation_id' => request()->attributes->get('correlation_id'),
                    ]);
                }
            }
            throw $exception;
        }

        return response()->json(['message' => 'Atualizado com sucesso!']);
    }

    public function listarRecebidos()
    {
        $os = $this->listarComSnapshots(OrdemServico::whereIn('statid_atual', [OsStatusInterface::RECEBIDA[1], OsStatusInterface::EM_DIAGNOSTICO[1]]));
        return response()->json($os);
    }

    public function emDiagnostico(string $id)
    {
        $registro = OrdemServico::where('osid', $id)->first();
        $os = $registro?->update([
                'status_atual' => OsStatusInterface::EM_DIAGNOSTICO[0],
                'statid_atual' => OsStatusInterface::EM_DIAGNOSTICO[1]
            ]);

        try {
            $emailDestinatario = $registro?->cliente_email_snapshot ?? 'cliente.teste@mwssandbox.com';

            Mail::to($emailDestinatario)->send(new OrcamentoEmDiagnostico($id));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Erro ao registrar disparo de e-mail para OS {$id}: " . $e->getMessage());
        }

        return response()->json([
            'sucesso' => $os > 0,
            'message' => $os > 0 ? 'Status atualizado' : 'OS não encontrada'
        ]);
    }

    public function analiseOs(Request $request, $id)
    {
        $os = OrdemServico::where('osid', $id)->first();
        if (!$os) return response()->json(['message' => "OS $id não encontrada"], 404);

        $items = $request->has('itens')
            ? collect($request->itens)->map(fn ($item) => is_array($item) ? $item : json_decode($item, true))
            : $os->itens()->get()->map(fn ($item) => [
                'id' => $item->estoqid, 'qtd' => $item->quantidade,
                'preco' => $item->valor_unitario,
            ]);
        if ($items->isEmpty() || $items->contains(fn ($item) => !is_array($item)
            || empty($item['id']) || !is_numeric($item['qtd'] ?? null)
            || (int) $item['qtd'] <= 0 || (float) $item['qtd'] !== (float) (int) $item['qtd']
            || !is_numeric($item['preco'] ?? null) || (float) $item['preco'] < 0)) {
            return response()->json(['message' => 'Itens de orçamento inválidos.'], 422);
        }
        $items = $items->groupBy('id')->map(fn ($group, $itemId) => [
            'id' => (int) $itemId,
            'qtd' => $group->sum('qtd'),
            'preco' => (float) $group->last()['preco'],
        ])->values();

        $stockIds = $items->pluck('id')->all();
        $stockResponse = app(ServicoInterno::class)->consultar('estoque', '/api/internal/v1/itens-estoque', [
            'ids' => $stockIds, 'include_archived' => true,
        ]);
        if (count($stockResponse) !== count($stockIds)) {
            return response()->json(['message' => 'Item de estoque não encontrado.'], 422);
        }
        $stockById = collect($stockResponse)->keyBy('estoqid');
        $currentById = $os->itens()->get()->groupBy('estoqid')->map(fn ($group) => $group->sum('quantidade'));
        $desiredById = $items->keyBy('id')->map(fn ($item) => $item['qtd']);
        $adjustments = [];
        foreach ($currentById->keys()->merge($desiredById->keys())->unique() as $stockId) {
            $difference = (int) $desiredById->get($stockId, 0) - (int) $currentById->get($stockId, 0);
            if ($difference === 0) continue;
            $stock = $stockById[$stockId] ?? null;
            if ($difference > 0 && (!$stock || !$stock['ativo']
                || ($stock['tipo'] === 'peca' && (int) $stock['quantidade_atual'] < $difference))) {
                return response()->json(['message' => 'Saldo de estoque insuficiente ou item arquivado.'], 409);
            }
            $adjustments[] = ['id' => (int) $stockId, 'qtd' => $difference];
        }

        $services = app(ServicoInterno::class);
        $stockAdjustmentApplied = false;
        if ($adjustments) {
            $services->ajustarEstoque($adjustments, (string) \Illuminate\Support\Str::uuid());
            $stockAdjustmentApplied = true;
        }

        try {
            DB::transaction(function () use ($os, $items, $stockById, $request, $id) {
                $os->update([
                    'analise' => $request->input('analise'),
                    'status_atual' => OsStatusInterface::AGUARDANDO_APROVACAO[0],
                    'statid_atual' => OsStatusInterface::AGUARDANDO_APROVACAO[1],
                    'valor_total' => $request->input('valor_total', $items->sum(fn ($item) => $item['qtd'] * $item['preco'])),
                ]);
                $os->itens()->delete();
                foreach ($items as $item) {
                    $os->itens()->create([
                        'estoqid' => $item['id'], 'quantidade' => $item['qtd'],
                        'valor_unitario' => $item['preco'],
                        'subtotal' => $item['qtd'] * $item['preco'],
                        'descricao_snapshot' => $stockById[$item['id']]['descricao'],
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            DogStatsD::metric('mws.os.processing.failures', 1, 'c', ['service' => 'os-lv', 'operation' => 'analysis']);
            if ($stockAdjustmentApplied) {
                try {
                    $services->ajustarEstoque(array_map(fn ($item) => ['id' => $item['id'], 'qtd' => -$item['qtd']], $adjustments), (string) \Illuminate\Support\Str::uuid());
                } catch (\Throwable $compensationFailure) {
                    \Illuminate\Support\Facades\Log::error('Falha ao compensar ajuste de estoque da OS ' . $id, [
                        'correlation_id' => request()->attributes->get('correlation_id'),
                    ]);
                }
            }
            throw $exception;
        }

        try {
            Mail::to($os->cliente_email_snapshot ?? 'cliente.teste@mwssandbox.com')
                ->send(new OrcamentoAguardandoAprovacao($os->refresh()));
        } catch (\Exception $exception) {
            \Illuminate\Support\Facades\Log::error("Erro ao registrar disparo de e-mail para OS {$id}: " . $exception->getMessage());
        }
        return response()->json(['message' => 'Análise finalizada e enviada para aprovação!']);
    }

    public function listarAguardandoAprovacao()
    {
        $os = $this->listarComSnapshots(OrdemServico::where('statid_atual', OsStatusInterface::AGUARDANDO_APROVACAO[1]));
        return response()->json($os);
    }

    public function aprovarOs($id)
    {
        $registro = OrdemServico::where('osid', $id)->first();
        $os = $registro?->update([
                'status_atual' => OsStatusInterface::EM_EXECUCAO[0],
                'statid_atual' => OsStatusInterface::EM_EXECUCAO[1]
            ]);

        try {
            $emailDestinatario = $registro?->cliente_email_snapshot ?? 'cliente.teste@mwssandbox.com';

            Mail::to($emailDestinatario)->send(new OrcamentoAprovado($id));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Erro ao registrar disparo de e-mail para OS {$id}: " . $e->getMessage());
        }

        return response()->json([
            'sucesso' => $os > 0,
            'message' => $os > 0 ? 'Status atualizado' : 'OS não encontrada'
        ]);
    }

    public function finalizarOs(string $id)
    {
        $registro = OrdemServico::where('osid', $id)->first();
        $os = $registro?->update([
                'status_atual' => OsStatusInterface::FINALIZADO[0],
                'statid_atual' => OsStatusInterface::FINALIZADO[1]
            ]);

        try {
            $emailDestinatario = $registro?->cliente_email_snapshot ?? 'cliente.teste@mwssandbox.com';

            Mail::to($emailDestinatario)->send(new OrcamentoFinalizado($id));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Erro ao registrar disparo de e-mail para OS {$id}: " . $e->getMessage());
        }

        return response()->json([
            'sucesso' => $os > 0,
            'message' => $os > 0 ? 'Status atualizado' : 'OS não encontrada'
        ]);
    }

    public function entregarOs(string $id)
    {
        $os = OrdemServico::where('osid', $id)
            ->update([
                'status_atual' => OsStatusInterface::ENTREGUE[0],
                'statid_atual' => OsStatusInterface::ENTREGUE[1],
                'entregue' => OrdemServicoInterface::ENTREGUE,
            ]);

        return response()->json([
            'sucesso' => $os > 0,
            'message' => $os > 0 ? 'Status atualizado' : 'OS não encontrada'
        ]);
    }

    public function listarExecucao()
    {
        $os = $this->listarComSnapshots(OrdemServico::whereIn('statid_atual', [OsStatusInterface::EM_EXECUCAO[1], OsStatusInterface::FINALIZADO[1]])->where('entregue', 0));
        return response()->json($os);
    }

    public function deletarOs(string $id)
    {
        $os = OrdemServico::where('osid', $id)->first();
        if (!$os) return response()->json(['message' => 'OS não encontrada'], 404);

        $itens = $os->itens()->get()->groupBy('estoqid')->map(fn ($group, $estoqueId) => [
            'id' => (int) $estoqueId, 'qtd' => (int) $group->sum('quantidade'),
        ])->values()->all();
        $services = app(ServicoInterno::class);
        $restockApplied = false;
        if ($itens) {
            $services->ajustarEstoque($itens, (string) \Illuminate\Support\Str::uuid());
            $restockApplied = true;
        }

        try {
            DB::transaction(function () use ($os) {
                $os->itens()->delete();
                $os->historicoStatus()->delete();
                $os->delete();
            });
        } catch (\Throwable $exception) {
            DogStatsD::metric('mws.os.processing.failures', 1, 'c', ['service' => 'os-lv', 'operation' => 'delete']);
            if ($restockApplied) {
                try {
                    $services->ajustarEstoque(array_map(fn ($item) => ['id' => $item['id'], 'qtd' => -$item['qtd']], $itens), (string) \Illuminate\Support\Str::uuid());
                } catch (\Throwable $compensationFailure) {
                    \Illuminate\Support\Facades\Log::critical('Falha ao compensar devolução de estoque na exclusão da OS ' . $id, [
                        'correlation_id' => request()->attributes->get('correlation_id'),
                    ]);
                }
            }
            throw $exception;
        }

        return response()->json(['message' => "OS #$id e seus vínculos foram excluídos com sucesso"]);
    }

    public function aprovarOsUsuario($id)
    {
        $os = OrdemServico::find($id);
        if (!$os) {
            return response()->json([
                'sucesso' => false,
                'message' => 'Os não encontrada'
            ], 404);
        }

        $os->update([
                'status_atual' => OsStatusInterface::EM_EXECUCAO[0],
                'statid_atual' => OsStatusInterface::EM_EXECUCAO[1]
            ]);

        try {
            $emailDestinatario = $os->cliente_email_snapshot ?? 'cliente.teste@mwssandbox.com';

            Mail::to($emailDestinatario)->send(new OrcamentoAprovado($id));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Erro ao registrar disparo de e-mail para OS {$id}: " . $e->getMessage());
        }

        return response()->json([
            'sucesso' => true,
            'message' => 'Status atualizado'
        ]);
    }

    public function reprovarOsUsuario(string $id)
    {
        $os = OrdemServico::find($id);
        if (!$os) {
            return response()->json([
                'sucesso' => false,
                'message' => 'Os não encontrada'
            ], 404);
        }

        if ((int) $os->statid_atual === OsStatusInterface::CANCELADO[1]) {
            return response()->json(['sucesso' => true, 'message' => 'OS já cancelada']);
        }

        $itens = $os->itens()->get()->groupBy('estoqid')->map(fn ($group, $estoqueId) => [
            'id' => (int) $estoqueId, 'qtd' => (int) $group->sum('quantidade'),
        ])->values()->all();
        $services = app(ServicoInterno::class);
        $restockApplied = false;
        if ($itens) {
            $services->ajustarEstoque($itens, (string) \Illuminate\Support\Str::uuid());
            $restockApplied = true;
        }

        try {
            DB::transaction(function () use ($os) {
                $os->update([
                    'status_atual' => OsStatusInterface::CANCELADO[0],
                    'statid_atual' => OsStatusInterface::CANCELADO[1]
                ]);
            });
        } catch (\Throwable $exception) {
            DogStatsD::metric('mws.os.processing.failures', 1, 'c', ['service' => 'os-lv', 'operation' => 'cancel']);
            if ($restockApplied) {
                try {
                    $services->ajustarEstoque(array_map(fn ($item) => ['id' => $item['id'], 'qtd' => -$item['qtd']], $itens), (string) \Illuminate\Support\Str::uuid());
                } catch (\Throwable $compensationFailure) {
                    \Illuminate\Support\Facades\Log::critical('Falha ao compensar devolução de estoque no cancelamento da OS ' . $id, [
                        'correlation_id' => request()->attributes->get('correlation_id'),
                    ]);
                }
            }
            throw $exception;
        }

        return response()->json([
            'sucesso' => true,
            'message' => 'Status atualizado'
        ]);
    }

    public function verificarOsCliente(string $cpf)
    {
        $clientes = app(ServicoInterno::class)->consultar('cliente', '/api/internal/v1/clientes', ['cpf' => $cpf]);
        $ids = collect($clientes)->pluck('clientid')->all();
        $os = $this->listarComSnapshots(OrdemServico::whereIn('clientid', $ids));
        return response()->json($os);
    }

    private function listarComSnapshots($query)
    {
        return $query->get()->each(function ($os) {
            $os->nome_cliente = $os->cliente_nome_snapshot;
            $os->modelo = $os->veiculo_modelo_snapshot;
            $os->placa = $os->veiculo_placa_snapshot;
            $os->nome_funcionario = $os->funcionario_nome_snapshot;
            $os->nome_mecanico = $os->funcionario_nome_snapshot;
        });
    }
}
