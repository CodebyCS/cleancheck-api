<?php

namespace App\Modules\Billing\Reports;

/**
 * CSV version of the monthly report, ready for Excel in Portuguese:
 * semicolon separator, decimal comma and a UTF-8 BOM so accents display correctly.
 */
class MonthlyReportCsv
{
    private const TASK_TYPES = [
        'checkout_cleaning' => 'Limpeza de checkout',
        'checkin_inspection' => 'Inspeção de check-in',
    ];

    private const AMOUNT_SOURCES = [
        'task_agreed_amount' => 'Valor acordado na tarefa',
        'rate' => 'Tarifa',
        'property_base' => 'Valor base do imóvel',
    ];

    /**
     * @param  array<string, mixed>  $report  Output of MonthlyReport::build()
     * @return list<list<string>>
     */
    public function rows(array $report): array
    {
        $rows = [[
            'Prestador', 'Data', 'Imóvel', 'Tipo de tarefa', 'Origem do valor',
            'Valor original', 'Ajustes', 'Valor final', 'Moeda', 'Estado',
        ]];

        foreach ($report['providers'] as $provider) {
            $name = (string) ($provider['name'] ?? 'Prestador #'.$provider['service_provider_id']);

            foreach ($provider['entries'] as $entry) {
                $rows[] = [
                    $name,
                    $this->date((string) $entry['service_date']),
                    (string) ($entry['property_name'] ?? 'Imóvel #'.$entry['property_id']),
                    self::TASK_TYPES[$entry['task_type']] ?? (string) $entry['task_type'],
                    self::AMOUNT_SOURCES[$entry['amount_source']] ?? (string) $entry['amount_source'],
                    $this->amount((string) $entry['amount']),
                    $this->amount((string) $entry['adjustments_total']),
                    $this->amount((string) $entry['final_amount']),
                    (string) $entry['currency'],
                    MonthlyReport::STATUS,
                ];
            }

            foreach ($provider['totals'] as $total) {
                $rows[] = $this->totalRow('Total '.$name, $total);
            }
        }

        foreach ($report['totals'] as $total) {
            $rows[] = $this->totalRow('Total geral', $total);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function content(array $report): string
    {
        $handle = fopen('php://temp', 'r+');

        // BOM so Excel opens the file as UTF-8.
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($this->rows($report) as $row) {
            fputcsv($handle, $row, ';', '"', '');
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * @param  array<string, mixed>  $total
     * @return list<string>
     */
    private function totalRow(string $label, array $total): array
    {
        return [
            $label,
            '',
            '',
            $total['services'].' '.($total['services'] === 1 ? 'serviço' : 'serviços'),
            '',
            $this->amount((string) $total['original_total']),
            $this->amount((string) $total['adjustments_total']),
            $this->amount((string) $total['total']),
            (string) $total['currency'],
            MonthlyReport::STATUS,
        ];
    }

    private function amount(string $value): string
    {
        return str_replace('.', ',', $value);
    }

    private function date(string $value): string
    {
        $parts = explode('-', $value);

        return count($parts) === 3 ? "{$parts[2]}/{$parts[1]}/{$parts[0]}" : $value;
    }
}
