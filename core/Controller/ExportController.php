<?php
/**
 * PlotBird Analytics - CSV export.
 *
 * One CSV per report, packed into a ZIP. The archive is built in the temporary
 * directory and streamed, so nothing is left behind on the hosting account.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird\Controller;

use PlotBird\I18n;
use PlotBird\Stats;
use PlotBird\StatsPayload;

final class ExportController extends BaseController
{
    /**
     * GET /export
     */
    public function download(): void
    {
        $this->guardLogin();
        $site = $this->requireSite();

        $params = [
            'preset' => (string) ($_GET['preset'] ?? 'last7'),
            'from'   => (string) ($_GET['from'] ?? ''),
            'to'     => (string) ($_GET['to'] ?? ''),
        ];

        $period = Stats::resolvePeriod($site, $params['preset'], $params['from'], $params['to']);
        $siteId = (int) $site['id'];
        $from   = (string) $period['from'];
        $to     = (string) $period['to'];

        // More rows than the dashboard shows: an export is for spreadsheets.
        $params['limit'] = 50;
        $payload = StatsPayload::build($site, $params);

        $files = [
            'summary.csv'     => $this->summaryRows($payload),
            'traffic.csv'     => $this->trafficRows($payload),
            'top-pages.csv'   => $this->dimensionRows($payload['top']['path'] ?? []),
            'sources.csv'     => $this->dimensionRows($payload['top']['referrer'] ?? []),
            'countries.csv'   => $this->dimensionRows($payload['top']['country'] ?? []),
            'devices.csv'     => $this->dimensionRows($payload['breakdown']['device'] ?? []),
            'systems.csv'     => $this->dimensionRows($payload['breakdown']['os'] ?? []),
            'browsers.csv'    => $this->dimensionRows($payload['breakdown']['browser'] ?? []),
            'goals.csv'       => $this->goalRows($payload['goals'] ?? []),
        ];

        $name = $this->fileBaseName((string) $site['domain'], $from, $to);

        $this->audit('export', 'site', $siteId, ['from' => $from, 'to' => $to]);

        // ZipArchive compresses, this writer does not. Both produce a valid
        // archive, so the export never degrades to a single file on hosts that
        // ship PHP without the zip extension.
        if (class_exists('ZipArchive')) {
            $this->sendZip($files, $name, $site, $period);
            return;
        }

        $this->sendStoredZip($files, $name, $site, $period);
    }

    /**
     * Header row plus the totals.
     *
     * @param array<string,mixed> $payload
     * @return list<list<string|int|float>>
     */
    private function summaryRows(array $payload): array
    {
        $summary = $payload['summary'] ?? [];

        return [
            [
                I18n::translate('metric.pageviews'),
                I18n::translate('metric.visitors'),
                I18n::translate('metric.sessions'),
                I18n::translate('metric.bounce_rate'),
                I18n::translate('metric.avg_time'),
            ],
            [
                (int) ($summary['pageviews'] ?? 0),
                (int) ($summary['visitors'] ?? 0),
                (int) ($summary['sessions'] ?? 0),
                round(((float) ($summary['bounce_rate'] ?? 0)) * 100, 1),
                (int) ($summary['avg_duration'] ?? 0),
            ],
        ];
    }

    /**
     * The daily series.
     *
     * @param array<string,mixed> $payload
     * @return list<list<string|int|float>>
     */
    private function trafficRows(array $payload): array
    {
        $labels    = $payload['series']['labels'] ?? [];
        $pageviews = $payload['series']['pageviews'] ?? [];
        $visitors  = $payload['series']['visitors'] ?? [];
        $sessions  = $payload['series']['sessions'] ?? [];

        $rows = [[
            I18n::translate('metric.date'),
            I18n::translate('metric.pageviews'),
            I18n::translate('metric.visitors'),
            I18n::translate('metric.sessions'),
        ]];

        foreach ($labels as $index => $label) {
            $rows[] = [
                (string) $label,
                (int) ($pageviews[$index] ?? 0),
                (int) ($visitors[$index] ?? 0),
                (int) ($sessions[$index] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * A dimension report.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<list<string|int|float>>
     */
    private function dimensionRows(array $rows): array
    {
        $out = [[
            I18n::translate('metric.value'),
            I18n::translate('metric.pageviews'),
            I18n::translate('metric.visitors'),
            I18n::translate('metric.sessions'),
        ]];

        foreach ($rows as $row) {
            $out[] = [
                (string) ($row['value'] ?? ''),
                (int) ($row['pageviews'] ?? 0),
                (int) ($row['visitors'] ?? 0),
                (int) ($row['sessions'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Goal conversions.
     *
     * @param array<string,mixed> $goals
     * @return list<list<string|int|float>>
     */
    private function goalRows(array $goals): array
    {
        $out = [[
            I18n::translate('widget.goals.title'),
            I18n::translate('metric.conversions'),
            I18n::translate('metric.visitors'),
        ]];

        foreach (($goals['rows'] ?? []) as $row) {
            $out[] = [
                (string) ($row['name'] ?? ''),
                (int) ($row['conversions'] ?? 0),
                (int) ($row['visitors'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * A file name that survives every filesystem the archive may travel to.
     */
    private function fileBaseName(string $domain, string $from, string $to): string
    {
        $name = $domain !== '' ? $domain : 'plotbird';
        $name = preg_replace('/[^a-z0-9.-]+/i', '-', $name) ?? 'plotbird';

        return 'plotbird-' . trim((string) $name, '-') . '-' . $from . '_' . $to;
    }

    /**
     * Stream a ZIP of CSVs.
     *
     * @param array<string,list<list<string|int|float>>> $files
     * @param array<string,mixed> $site
     * @param array<string,mixed> $period
     */
    private function sendZip(array $files, string $name, array $site, array $period): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'pbexport');

        if ($temp === false) {
            $this->sendStoredZip($files, $name, $site, $period);
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($temp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($temp);
            $this->sendStoredZip($files, $name, $site, $period);
            return;
        }

        foreach ($files as $fileName => $rows) {
            $zip->addFromString($fileName, $this->toCsv($rows));
        }

        $zip->addFromString('README.txt', $this->readme($site, $period, $files));
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '.zip"');
        header('Content-Length: ' . (string) filesize($temp));
        header('Cache-Control: no-store');

        readfile($temp);
        @unlink($temp);
    }

    /**
     * Stream an archive built without the zip extension.
     *
     * @param array<string,list<list<string|int|float>>> $files
     * @param array<string,mixed> $site
     * @param array<string,mixed> $period
     */
    private function sendStoredZip(array $files, string $name, array $site, array $period): void
    {
        $zip = new \PlotBird\ZipWriter();

        foreach ($files as $fileName => $rows) {
            $zip->add($fileName, $this->toCsv($rows));
        }
        $zip->add('README.txt', $this->readme($site, $period, $files));

        $archive = $zip->finish();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '.zip"');
        header('Content-Length: ' . (string) strlen($archive));
        header('Cache-Control: no-store');

        echo $archive;
    }

    /**
     * Rows to CSV text.
     *
     * @param list<list<string|int|float>> $rows
     */
    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    /**
     * A short note inside the archive explaining what the numbers mean.
     *
     * @param array<string,mixed> $site
     * @param array<string,mixed> $period
     * @param array<string,mixed> $files
     */
    private function readme(array $site, array $period, array $files): string
    {
        $lines = [
            'PlotBird Analytics export',
            '',
            'Website : ' . (string) $site['name'] . ($site['domain'] !== '' ? ' (' . (string) $site['domain'] . ')' : ''),
            'Period  : ' . (string) $period['from'] . ' .. ' . (string) $period['to'],
            'Timezone: ' . (string) $site['timezone'],
            'Created : ' . gmdate('Y-m-d H:i:s') . ' UTC',
            '',
            'Files:',
        ];

        foreach (array_keys($files) as $fileName) {
            $lines[] = '  ' . $fileName;
        }

        $lines[] = '';
        $lines[] = 'A visitor is a salted daily hash of the anonymised address and the user agent.';
        $lines[] = 'A session ends after 30 minutes of inactivity. A bounce is a session with one page view.';

        return implode("\n", $lines) . "\n";
    }
}
