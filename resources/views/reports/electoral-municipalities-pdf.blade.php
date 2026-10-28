<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 14px 18px; }
        * { box-sizing: border-box; }
        body { background: #f4f6fa; color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 8px; margin: 0; }
        .municipality-page { page-break-after: always; }
        .municipality-page:last-child { page-break-after: auto; }
        .municipality-title { background: #ffffff; border: 1px solid #d9e1ed; border-top: 3px solid #4385f5; border-radius: 7px; font-size: 14px; font-weight: bold; padding: 6px 10px; }
        .summary-grid { border-collapse: separate; border-spacing: 5px 0; margin: 7px -5px 0; width: calc(100% + 10px); }
        .summary-card { background: #ffffff; border: 1px solid #d9e1ed; border-top: 3px solid #4385f5; border-radius: 7px; height: 55px; padding: 6px 8px; vertical-align: top; width: 20%; }
        .summary-label { color: #52678b; display: block; font-size: 7px; font-weight: bold; }
        .summary-value { color: #101827; display: block; font-size: 14px; font-weight: bold; margin-top: 4px; }
        .summary-detail { color: #52678b; display: block; font-size: 7px; margin-top: 3px; }
        .panel-grid { border-collapse: separate; border-spacing: 5px 0; margin: 8px -5px 0; width: calc(100% + 10px); }
        .panel-cell { padding: 0; vertical-align: top; width: 50%; }
        .panel { background: #ffffff; border: 1px solid #d9e1ed; border-radius: 7px; min-height: 164px; overflow: hidden; }
        .panel-heading { border-bottom: 1px solid #e7edf5; padding: 8px 10px 6px; }
        .panel-title { color: #172033; display: block; font-size: 10px; font-weight: bold; }
        .panel-subtitle { color: #52678b; display: block; font-size: 7px; margin-top: 3px; }
        .result-list { padding: 5px 10px 3px; }
        .result-row { border-bottom: 1px solid #edf1f6; padding: 4px 0 3px; }
        .result-row:last-child { border-bottom: 0; }
        .result-head { width: 100%; }
        .rank-cell { padding: 0; vertical-align: middle; width: 20px; }
        .result-rank { color: #52678b; display: block; font-size: 7px; font-weight: bold; height: 14px; line-height: 14px; margin: 0 auto; text-align: center; width: 14px; }
        .result-rank.first { color: #172033; }
        .result-name { color: #172033; font-size: 7px; font-weight: bold; padding-left: 5px; }
        .result-percentage { color: #172033; font-size: 8px; font-weight: bold; text-align: right; }
        .result-votes { color: #52678b; font-size: 7px; padding-left: 19px; }
        .track { background: #eaf0f6; border-radius: 3px; height: 5px; margin: 3px 0 0 19px; }
        .fill { background: #3274bd; border-radius: 3px; height: 5px; }
        .panel-total { border-top: 1px solid #e7edf5; font-size: 8px; font-weight: bold; padding: 6px 10px; text-align: right; }
        .chart-panel { background: #ffffff; border: 1px solid #d9e1ed; border-radius: 7px; margin-top: 8px; overflow: hidden; }
        .chart-content { border-top: 1px solid #e7edf5; padding: 5px 10px; }
        .chart-layout { border-collapse: collapse; width: 100%; }
        .chart-layout td { border: 0; padding: 0; vertical-align: middle; }
        .donut { height: 200px; width: 300px; }
        .legend { border-collapse: collapse; width: 100%; }
        .legend td { border: 0; padding: 2px 3px; }
        .legend-color { border-radius: 50%; display: inline-block; height: 7px; width: 7px; }
        .legend-name { color: #273650; font-size: 7px; }
        .legend-value { color: #273650; font-size: 7px; font-weight: bold; text-align: right; white-space: nowrap; }
        .empty { color: #52678b; padding: 12px; text-align: center; }
    </style>
</head>
<body>
@php
    $formatNumber = static fn (mixed $value): string => number_format((int) ($value ?? 0), 0, ',', '.');
    $formatPercentage = static function (mixed $value): string {
        $parts = explode('.', sprintf('%.14F', (float) ($value ?? 0)), 2);
        $decimalPart = substr(str_pad($parts[1] ?? '', 2, '0'), 0, 2);

        return number_format((int) $parts[0], 0, ',', '.').','.$decimalPart.' %';
    };
    $barWidth = static fn (mixed $value): string => min(100, max(0, (float) ($value ?? 0))).'%';
    $pieColors = ['#f5b62d', '#713d7d', '#1f65ad', '#159bc9', '#d6172c', '#8faf5c', '#e7bf4e', '#bd526c', '#5b4b91', '#e66052', '#b57945'];
@endphp

@foreach ($reports as $report)
    @php
        $summary = $report['summary'];
        $chargeName = $report['charge']['name'];
        $positiveRows = array_values(array_filter($report['positive_slogans'], static fn (array $row): bool => $row['votes'] > 0));
        $positiveVotes = array_sum(array_column($positiveRows, 'votes'));
    @endphp
    <div class="municipality-page">
        <div class="municipality-title">Municipio: {{ $report['municipality']['name'] }}</div>

        <table class="summary-grid">
            <tr>
                <td class="summary-card">
                    <span class="summary-label">Electores habilitados</span>
                    <span class="summary-value">{{ $formatNumber($summary['eligible_voters']) }}</span>
                    <span class="summary-detail">{{ $formatNumber($summary['enabled_tables']) }} mesas habilitadas</span>
                </td>
                <td class="summary-card">
                    <span class="summary-label">Votantes</span>
                    <span class="summary-value">{{ $formatNumber($summary['voters']) }}</span>
                    <span class="summary-detail">{{ $formatPercentage($summary['voters_percentage']) }} de participación</span>
                </td>
                <td class="summary-card">
                    <span class="summary-label">Votos positivos</span>
                    <span class="summary-value">{{ $formatNumber($summary['positive_voters']) }}</span>
                    <span class="summary-detail">{{ $formatPercentage($summary['positive_voters_percentage']) }} del padrón</span>
                </td>
                <td class="summary-card">
                    <span class="summary-label">Mesas escrutadas</span>
                    <span class="summary-value">{{ $formatNumber($summary['counted_tables']) }}</span>
                    <span class="summary-detail">{{ $formatPercentage($summary['counted_tables_percentage']) }} de {{ $formatNumber($summary['enabled_tables']) }} mesas</span>
                </td>
                <td class="summary-card">
                    <span class="summary-label">Cargo</span>
                    <span class="summary-value" style="font-size: 11px;">{{ $chargeName }}</span>
                    <span class="summary-detail">Reporte general</span>
                </td>
            </tr>
        </table>

        <table class="panel-grid">
            <tr>
                <td class="panel-cell">
                    <div class="panel">
                        <div class="panel-heading">
                            <span class="panel-title">Votos positivos por lema</span>
                            <span class="panel-subtitle">Porcentaje sobre el total de votos positivos.</span>
                        </div>
                        <div class="result-list">
                        @forelse ($report['positive_slogans'] as $index => $row)
                            <div class="result-row">
                                <table class="result-head"><tr>
                                    <td class="rank-cell"><span class="result-rank {{ $index === 0 ? 'first' : '' }}">{{ $index + 1 }}</span></td>
                                    <td class="result-name">{{ $row['name'] }}</td>
                                    <td class="result-percentage">{{ $formatPercentage($row['percentage']) }}</td>
                                </tr></table>
                                <div class="track"><div class="fill" style="width: {{ $barWidth($row['percentage']) }}"></div></div>
                                <div class="result-votes">{{ $formatNumber($row['votes']) }} votos</div>
                            </div>
                        @empty
                            <div class="empty">Sin resultados positivos</div>
                        @endforelse
                        </div>
                        <div class="panel-total">Total <span style="float: right;">{{ $formatNumber($summary['positive_voters']) }} votos</span></div>
                    </div>
                </td>
                <td class="panel-cell">
                    <div class="panel">
                        <div class="panel-heading">
                            <span class="panel-title">Otros votos</span>
                            <span class="panel-subtitle">Nulos, blancos, recurridos e identidad impugnada.</span>
                        </div>
                        <div class="result-list">
                        @forelse ($report['non_positive_slogans'] as $index => $row)
                            <div class="result-row">
                                <table class="result-head"><tr>
                                    <td class="rank-cell"><span class="result-rank">{{ $index + 1 }}</span></td>
                                    <td class="result-name">{{ $row['name'] }}</td>
                                    <td class="result-percentage">{{ $formatPercentage($row['percentage']) }}</td>
                                </tr></table>
                                <div class="track"><div class="fill" style="width: {{ $barWidth($row['percentage']) }}"></div></div>
                                <div class="result-votes">{{ $formatNumber($row['votes']) }} votos</div>
                            </div>
                        @empty
                            <div class="empty">Sin otros votos</div>
                        @endforelse
                        </div>
                        <div class="panel-total">Total <span style="float: right;">{{ $formatNumber($summary['voters'] - $summary['positive_voters']) }} votos</span></div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="chart-panel">
            <div class="panel-heading">
                <span class="panel-title">Gráfico de votos positivos</span>
                <span class="panel-subtitle">Porcentaje sobre el total de votos positivos.</span>
            </div>
            @if ($positiveVotes > 0)
                <div class="chart-content">
                    <table class="chart-layout">
                        <tr>
                            <td style="width: 245px;">
                                <img class="donut" src="{{ $report['positive_chart'] }}" alt="Distribución de votos positivos">
                            </td>
                            <td>
                                <table class="legend">
                                    <tbody>
                                    @foreach ($positiveRows as $index => $row)
                                        @php $legendColor = $row['color'] ?: $row['rgba_background'] ?: $pieColors[$index % count($pieColors)]; @endphp
                                        <tr>
                                            <td style="width: 16px;"><span class="legend-color" style="background: {{ $legendColor }}"></span></td>
                                            <td class="legend-name">{{ $row['name'] }}</td>
                                            <td class="legend-value">{{ $formatPercentage($row['percentage']) }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </table>
                </div>
            @else
                <div class="empty">Sin votos positivos para graficar</div>
            @endif
        </div>
    </div>
@endforeach
</body>
</html>
