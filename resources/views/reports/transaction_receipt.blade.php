<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recibo #{{ $exchange->number }}</title>
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
            position: absolute; top: 200px; right: 50px;
            border: 4px solid #27ae60; color: #27ae60;
            font-size: 40px; font-weight: bold; padding: 10px 30px;
            text-transform: uppercase; opacity: 0.2; transform: rotate(-20deg);
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

    <div class="stamp {{ $exchange->status !== 'completed' ? 'pending' : '' }}">
        {{ $exchange->status === 'completed' ? 'PAGADO' : 'PENDIENTE' }}
    </div>

    <div class="header">
        <div class="company-name">{{ $company->name ?? 'KHEPAS FINANCIAL' }}</div>
        <div class="company-sub">Comprobante de Operación de Cambio</div>
    </div>

    <div class="receipt-info">
        <table width="100%">
            <tr>
                <td align="left">
                    <div class="receipt-number">RECIBO: {{ $exchange->number }}</div>
                </td>
                <td align="right">
                    <div class="receipt-date">Fecha: {{ $exchange->created_at->format('d/m/Y h:i A') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="client-box">
        <div class="client-label">Cliente / Beneficiario</div>
        <div class="client-name">{{ $exchange->client->name ?? 'Cliente General' }}</div>
        <div style="font-size: 12px; margin-top: 5px;">ID: {{ $exchange->client->document_id ?? '---' }}</div>
    </div>

    <table class="details-table">
        <tr>
            <td class="label">Operación</td>
            <td class="value">{{ strtoupper($exchange->type === 'purchase' ? 'Compra de Divisa' : 'Intercambio') }}</td>
        </tr>
        <tr>
            <td class="label">Monto Enviado (Recibimos)</td>
            <td class="value text-danger">
                {{ number_format($exchange->amount_sent, 2) }} 
                <small>{{ $exchange->from_account_id ? 'MONEDA ORIGEN' : 'USD' }}</small>
            </td>
        </tr>
        <tr>
            <td class="label">Tasa de Cambio</td>
            <td class="value">{{ number_format($exchange->exchange_rate, 2) }}</td>
        </tr>
        <tr>
            <td class="label" style="font-size: 16px;">MONTO ENTREGADO</td>
            <td class="value amount-big">
                {{ number_format($exchange->amount_received, 2) }}
                <small>{{ $exchange->toAccount->currency_code ?? 'USD' }}</small>
            </td>
        </tr>
    </table>

    <div class="signatures">
        <div class="sign-box">
            <div class="sign-line">Por: {{ $company->name ?? 'La Empresa' }}</div>
        </div>
        <div class="sign-box">
            <div class="sign-line">Conforme Cliente</div>
        </div>
    </div>

    <div class="footer">
        Este documento es un comprobante válido emitido por el sistema Khepas.<br>
        Generado por: {{ $exchange->user->name ?? 'Sistema' }}
    </div>

</body>
</html>