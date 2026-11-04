<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdemServico extends Model
{
    protected $table = 'os';
    protected $primaryKey = 'osid';

    protected $fillable = [
        'clientid', 'carid', 'funcid', 'sintomas', 'valor_total', 'status_atual', 'statid_atual', 'entregue',
        'cliente_nome_snapshot', 'cliente_email_snapshot', 'veiculo_modelo_snapshot', 'veiculo_placa_snapshot', 'funcionario_nome_snapshot', 'analise'
    ];

    public function itens()
    {
        return $this->hasMany(OrdemServicoItem::class, 'osid', 'osid');
    }

    public function historicoStatus()
    {
        return $this->hasMany(OrdemServicoStatus::class, 'osid', 'osid');
    }

}
