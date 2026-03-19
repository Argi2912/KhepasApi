<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante #{{ $type === 'exchange' ? $transaction->number : 'TRX-' . $transaction->id }}</title>
    <style>
        body { font-family: 'Helvetica', sans-serif; color: #333; padding: 20px; }
        
        .header { text-align: center; margin-bottom: 40px; border-bottom: 2px solid #004d40; padding-bottom: 20px; }
        .company-name { font-size: 24px; font-weight: bold; color: #004d40; text-transform: uppercase; }
        .company-sub { font-size: 12px; color: #666; margin-top: 5px; }

        .receipt-info { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .receipt-number { font-size: 18px; font-weight: bold; color: #c0392b; }
        .receipt-date { font-size: 14px; color: #555; }

        .client-box { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 30px; border-left: 5px solid #004d40; }
        .client-label { font-size: 10px; text-transform: uppercase; color: #999; font-weight: bold; }
        .client-name { font-size: 18px; font-weight: bold; margin-top: 5px; }

        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
        .details-table td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; }
        .details-table .label { font-weight: bold; color: #555; width: 40%; }
        .details-table .value { text-align: right; font-weight: bold; font-size: 16px; }
        
        .amount-big { font-size: 22px !important; color: #27ae60; }

        /* Sello de Estado */
        .stamp {
            position: absolute; top: 180px; right: 50px;
            border: 4px solid #27ae60; color: #27ae60;
            font-size: 40px; font-weight: bold; padding: 10px 30px;
            text-transform: uppercase; opacity: 0.15; transform: rotate(-15deg);
            border-radius: 10px;
        }
        .pending { border-color: #e67e22; color: #e67e22; }

        .footer { position: fixed; bottom: 30px; left: 0; right: 0; text-align: center; font-size: 10px; color: #aaa; }
        .signatures { margin-top: 80px; display: table; width: 100%; }
        .sign-box { display: table-cell; width: 50%; text-align: center; }
        .sign-line { border-top: 1px solid #333; width: 60%; margin: 0 auto; padding-top: 10px; font-weight: bold; }
    </style>
</head>
<body>

    @php
        $statusStyle = ($type === 'exchange' && $transaction->status !== 'completed') ? 'pending' : '';
        $statusText = ($type === 'exchange' && $transaction->status !== 'completed') ? 'PENDIENTE' : 'COMPLETADO';
        
        $refNumber = $type === 'exchange' ? $transaction->number : 'TRX-' . str_pad($transaction->id, 6, '0', STR_PAD_LEFT);
        $date = $type === 'exchange' ? $transaction->created_at : $transaction->transaction_date;
        
        $entityName = 'N/A';
        if ($type === 'exchange') {
            $entityName = $transaction->client->name ?? 'Cliente General';
        } else {
            $entityName = $transaction->entity->name ?? ($transaction->person_name ?? 'Caja General');
        }
    @endphp

    <div class="stamp {{ $statusStyle }}">
        {{ $statusText }}
    </div>

    <div class="header">
        <div class="company-name">{{ $company->name ?? 'KHEPAS FINANCIAL' }}</div>
        <div class="company-sub">Comprobante Oficial de Operación</div>
    </div>

    <div class="receipt-info">
        <table width="100%">
            <tr>
                <td align="left">
                    <div class="receipt-number">RECIBO: {{ $refNumber }}</div>
                </td>
                <td align="right">
                    <div class="receipt-date">Fecha: {{ $date->format('d/m/Y h:i A') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="client-box">
        <div class="client-label">{{ $type === 'exchange' ? 'Cliente / Beneficiario' : 'Entrada / Salida de Capital' }}</div>
        <div class="client-name">{{ $entityName }}</div>
        @if($type === 'exchange' && isset($transaction->client->document_id))
            <div style="font-size: 12px; margin-top: 5px;">ID: {{ $transaction->client->document_id }}</div>
        @endif
    </div>

    <table class="details-table">
        <tr>
            <td class="label">Tipo de Movimiento</td>
            <td class="value">
                @if($type === 'exchange')
                    {{ strtoupper($transaction->type === 'purchase' ? 'Compra de Divisa' : 'Intercambio') }}
                @else
                    {{ strtoupper($transaction->type === 'income' ? 'Ingreso Operativo' : 'Egreso / Gastos') }}
                @endif
            </td>
        </tr>
        
        @if($type === 'exchange')
            <tr>
                <td class="label">Monto Enviado (Recibimos)</td>
                <td class="value" style="color: #c0392b;">
                    {{ number_format($transaction->amount_sent, 2) }} 
                    <small>{{ $transaction->fromAccount->currency_code ?? 'USD' }}</small>
                </td>
            </tr>
            <tr>
                <td class="label">Tasa de Cambio</td>
                <td class="value">{{ number_format($transaction->exchange_rate, 2) }}</td>
            </tr>
            <tr>
                <td class="label" style="font-size: 16px;">MONTO ENTREGADO</td>
                <td class="value amount-big">
                    {{ number_format($transaction->amount_received, 2) }}
                    <small>{{ $transaction->toAccount->currency_code ?? 'USD' }}</small>
                </td>
            </tr>
        @else
            <tr>
                <td class="label">Categoría / Concepto</td>
                <td class="value">{{ $transaction->category }}</td>
            </tr>
            <tr>
                <td class="label">Descripción</td>
                <td class="value" style="font-size: 12px; font-weight: normal;">{{ $transaction->description }}</td>
            </tr>
            <tr>
                <td class="label" style="font-size: 16px;">MONTO TOTAL</td>
                <td class="value amount-big">
                    {{ number_format($transaction->amount, 2) }}
                    <small>{{ $transaction->account->currency_code ?? 'USD' }}</small>
                </td>
            </tr>
        @endif
    </table>

    <div class="signatures">
        <div class="sign-box">
            <div class="sign-line">Firma Autorizada</div>
            <div style="font-size: 10px; margin-top: 5px;">{{ $company->name ?? 'La Empresa' }}</div>
        </div>
        <div class="sign-box">
            <div class="sign-line">Conforme</div>
            <div style="font-size: 10px; margin-top: 5px;">{{ $entityName }}</div>
        </div>
    </div>

    <div class="footer">
        Este documento es un comprobante válido emitido por el sistema Khepas.<br>
        Generado por: {{ ($type === 'exchange' ? ($transaction->adminUser->name ?? 'Sistema') : ($transaction->user->name ?? 'Sistema')) }}
    </div>

</body>
</html>