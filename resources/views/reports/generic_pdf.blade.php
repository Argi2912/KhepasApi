<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 100px 25px; }
        body { font-family: 'Helvetica', sans-serif; font-size: 12px; color: #333; line-height: 1.4; }
        
        /* HEADER FIJO */
        header { position: fixed; top: -60px; left: 0px; right: 0px; height: 50px; border-bottom: 2px solid #004d40; padding-bottom: 10px; }
        .company-name { font-size: 20px; font-weight: bold; color: #004d40; text-transform: uppercase; float: left; }
        .report-date { float: right; font-size: 10px; color: #666; margin-top: 5px; }

        /* TÍTULO DEL REPORTE */
        .report-title { text-align: center; margin-top: 20px; margin-bottom: 30px; }
        .report-title h1 { margin: 0; font-size: 18px; text-transform: uppercase; letter-spacing: 1px; }
        .report-title p { margin: 5px 0; font-size: 11px; color: #7f8c8d; }

        /* TABLA DE DATOS */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #f4f6f7; color: #2c3e50; font-weight: bold; text-transform: uppercase; font-size: 10px; border-top: 1px solid #ddd; }
        tr:nth-child(even) { background-color: #fafafa; }
        td { font-size: 11px; }

        /* ESTILOS ESPECÍFICOS PARA COLUMNAS */
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .font-bold { font-weight: bold; }
        .text-success { color: #27ae60; font-weight: bold; }
        .text-danger { color: #c0392b; font-weight: bold; }
        
        /* SELLO DE ESTADO (Para recibos individuales) */
        .stamp {
            position: absolute; 
            top: 150px; 
            right: 50px; 
            border: 3px solid #27ae60; 
            color: #27ae60; 
            font-size: 30px; 
            font-weight: bold; 
            padding: 10px 20px; 
            text-transform: uppercase; 
            opacity: 0.3; 
            transform: rotate(-15deg);
            border-radius: 10px;
        }
        .stamp.pending { border-color: #e67e22; color: #e67e22; }

        /* FOOTER */
        footer { position: fixed; bottom: -60px; left: 0px; right: 0px; height: 40px; font-size: 9px; text-align: center; color: #aaa; border-top: 1px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>
    <header>
        <div class="company-name">Reportes</div>
        <div class="report-date">Generado: {{ date('d/m/Y H:i A') }}</div>
    </header>

    <footer>
        <p>Documento generado electrónicamente. Página <span class="page-number"></span></p>
    </footer>

    <div class="main-content">
        
        <div class="report-title">
            <h1>{{ $title }}</h1>
            @if(isset($dateRange))
                <p>Periodo: {{ $dateRange }}</p>
            @endif
        </div>

        <table>
            <thead>
                <tr>
                    @foreach($headers as $header)
                        <th class="{{ is_numeric(strpos(strtolower($header), 'monto')) || is_numeric(strpos(strtolower($header), 'vol')) ? 'text-right' : '' }}">
                            {{ $header }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($data as $row)
                    <tr>
                        @foreach($row as $key => $cell)
                            @php 
                                // Detección automática para alinear números a la derecha
                                $isNumeric = is_numeric(str_replace([',','.'], '', $cell)) && strlen($cell) < 20;
                                $isStatus = in_array(strtoupper($cell), ['COMPLETED', 'PENDING', 'PAGADO', 'PENDIENTE']);
                                
                                $class = '';
                                if($isNumeric) $class = 'text-right font-bold';
                                if($isStatus) {
                                    $class = 'text-center font-bold ' . (in_array(strtoupper($cell), ['COMPLETED', 'PAGADO']) ? 'text-success' : 'text-danger');
                                }
                            @endphp
                            
                            <td class="{{ $class }}">
                                {{ $cell }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if(isset($totals) && !empty($totals))
            <div style="margin-top: 20px; float: right; width: 300px;">
                <table style="border: 2px solid #ddd;">
                    @foreach($totals as $label => $amount)
                        <tr>
                            <td style="background: #eee; font-weight: bold;">{{ $label }}</td>
                            <td class="text-right">{{ $amount }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif

    </div>
</body>
</html>