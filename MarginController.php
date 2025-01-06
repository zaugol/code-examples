<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
use PDO;

class MarginController
{
    private $view;
    private $db;

    public function __construct(PhpRenderer $view, PDO $db)
    {
        $this->view = $view;
        $this->db = $db;
    }

    public function index(Request $request, Response $response): Response
    {
        // Get parameters
        $params = $request->getQueryParams();
        $advertiser_id = $params['advertiser_id'] ?? 'all';
        $channel_id = $params['channel_id'] ?? 'all';
        $source_id = $params['source_id'] ?? 'all';
        $start_date = $params['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $end_date = $params['end_date'] ?? date('Y-m-d');

        // Build WHERE clause
        $where = ['m.date BETWEEN :start_date AND :end_date'];
        $queryParams = [
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ];

        if ($advertiser_id !== 'all') {
            $where[] = 'm.advertiser_id = :advertiser_id';
            $queryParams[':advertiser_id'] = $advertiser_id;
        }
        if ($channel_id !== 'all') {
            $where[] = 'm.channel_id = :channel_id';
            $queryParams[':channel_id'] = $channel_id;
        }
        if ($source_id !== 'all') {
            $where[] = 'm.source_id = :source_id';
            $queryParams[':source_id'] = $source_id;
        }

        $whereClause = implode(' AND ', $where);

        // Get data
        $query = "
            WITH margin_data AS (
                SELECT 
                    m.date,
                    a.name as advertiser,
                    c.name as channel,
                    s.name as source,
                    st.name as source_type,
                    m.ecpm,
                    m.impressions_good as impressions,
                    m.revenue_channel,
                    m.revenue_total,
                    (m.revenue_total - m.revenue_channel) as gross_profit,
                    CASE 
                        WHEN m.revenue_total > 0 
                        THEN ((m.revenue_total - m.revenue_channel) * 100 / m.revenue_total)
                        ELSE 0 
                    END as gross_margin,
                    CASE 
                        WHEN st.id = 14 THEN (m.impressions_good / 1000 * 0.15)
                        WHEN st.id = 15 THEN (m.impressions_good / 1000 * 0.02)
                        ELSE 0 
                    END as cost_imps,
                    (m.revenue_total - m.revenue_channel - 
                    CASE 
                        WHEN st.id = 14 THEN (m.impressions_good / 1000 * 0.15)
                        WHEN st.id = 15 THEN (m.impressions_good / 1000 * 0.02)
                        ELSE 0 
                    END) as net_profit,
                    CASE 
                        WHEN m.revenue_total > 0 
                        THEN ((m.revenue_total - m.revenue_channel - 
                        CASE 
                            WHEN st.id = 14 THEN (m.impressions_good / 1000 * 0.15)
                            WHEN st.id = 15 THEN (m.impressions_good / 1000 * 0.02)
                            ELSE 0 
                        END) * 100 / m.revenue_total)
                        ELSE 0 
                    END as net_margin
                FROM rtb_margin m
                JOIN advertisers a ON m.advertiser_id = a.id
                JOIN channels c ON m.channel_id = c.id
                JOIN sources s ON m.source_id = s.id
                JOIN source_types st ON m.source_type_id = st.id
                WHERE {$whereClause}
            )
            SELECT * FROM (
                SELECT 
                    date, 
                    advertiser,
                    channel,
                    source,
                    source_type,
                    ecpm,
                    impressions,
                    revenue_channel,
                    revenue_total,
                    gross_profit,
                    gross_margin,
                    cost_imps,
                    net_profit,
                    net_margin,
                    (gross_margin - net_margin) as margin_difference
                FROM margin_data

                UNION ALL

                SELECT 
                    'Total' as date,
                    '' as advertiser,
                    '' as channel,
                    '' as source,
                    '' as source_type,
                    ROUND(SUM(revenue_total) * 1000 / NULLIF(SUM(impressions), 0), 4) as ecpm,
                    SUM(impressions) as impressions,
                    SUM(revenue_channel) as revenue_channel,
                    SUM(revenue_total) as revenue_total,
                    SUM(gross_profit) as gross_profit,
                    CASE 
                        WHEN SUM(revenue_total) > 0 
                        THEN (SUM(gross_profit) * 100 / SUM(revenue_total))
                        ELSE 0 
                    END as gross_margin,
                    SUM(cost_imps) as cost_imps,
                    SUM(net_profit) as net_profit,
                    CASE 
                        WHEN SUM(revenue_total) > 0 
                        THEN (SUM(net_profit) * 100 / SUM(revenue_total))
                        ELSE 0 
                    END as net_margin,
                    CASE 
                        WHEN SUM(revenue_total) > 0 
                        THEN (
                            (SUM(gross_profit) * 100 / SUM(revenue_total)) - 
                            (SUM(net_profit) * 100 / SUM(revenue_total))
                        )
                        ELSE 0 
                    END as margin_difference
                FROM margin_data
            ) result
            ORDER BY date = 'Total', date ASC";

        // Get advertisers and other dropdowns data
        $advertisers = $this->db->query("SELECT id, name FROM advertisers ORDER BY name")->fetchAll();
        $channels = $this->db->query("SELECT id, name FROM channels ORDER BY name")->fetchAll();
        $sources = $this->db->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();

        $stmt = $this->db->prepare($query);
        $stmt->execute($queryParams);
        $stats = $stmt->fetchAll();

        return $this->view->render($response, 'margin/index.php', [
            'advertisers' => $advertisers,
            'channels' => $channels,
            'sources' => $sources,
            'stats' => $stats,
            'advertiser_id' => $advertiser_id,
            'channel_id' => $channel_id,
            'source_id' => $source_id,
            'start_date' => $start_date,
            'end_date' => $end_date
        ]);
    }

    public function export(Request $request, Response $response): Response
    {
        try {
            // Get parameters
            $params = $request->getQueryParams();
            $advertiser_id = $params['advertiser_id'] ?? 'all';
            $channel_id = $params['channel_id'] ?? 'all';
            $source_id = $params['source_id'] ?? 'all';
            $start_date = $params['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $end_date = $params['end_date'] ?? date('Y-m-d');

            // Build WHERE clause
            $where = ['m.date BETWEEN :start_date AND :end_date'];
            $queryParams = [
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ];

            if ($advertiser_id !== 'all') {
                $where[] = 'm.advertiser_id = :advertiser_id';
                $queryParams[':advertiser_id'] = $advertiser_id;
            }
            if ($channel_id !== 'all') {
                $where[] = 'm.channel_id = :channel_id';
                $queryParams[':channel_id'] = $channel_id;
            }
            if ($source_id !== 'all') {
                $where[] = 'm.source_id = :source_id';
                $queryParams[':source_id'] = $source_id;
            }

            $whereClause = implode(' AND ', $where);

            $query = "SELECT 
                m.date,
                a.name as advertiser,
                c.name as channel,
                s.name as source,
                st.name as source_type,
                m.ecpm,
                m.impressions_good as impressions,
                m.revenue_channel,
                m.revenue_total,
                (m.revenue_total - m.revenue_channel) as gross_profit,
                CASE 
                    WHEN m.revenue_total > 0 
                    THEN ((m.revenue_total - m.revenue_channel) * 100 / m.revenue_total)
                    ELSE 0 
                END as gross_margin,
                CASE 
                    WHEN st.id = 14 THEN (m.impressions_good / 1000 * 0.15)
                    WHEN st.id = 15 THEN (m.impressions_good / 1000 * 0.02)
                    ELSE 0 
                END as cost_imps,
                (m.revenue_total - m.revenue_channel - 
                CASE 
                    WHEN st.id = 14 THEN (m.impressions_good / 1000 * 0.15)
                    WHEN st.id = 15 THEN (m.impressions_good / 1000 * 0.02)
                    ELSE 0 
                END) as net_profit,
                CASE 
                    WHEN m.revenue_total > 0 
                    THEN ((m.revenue_total - m.revenue_channel - 
                    CASE 
                        WHEN st.id = 14 THEN (m.impressions_good / 1000 * 0.15)
                        WHEN st.id = 15 THEN (m.impressions_good / 1000 * 0.02)
                        ELSE 0 
                    END) * 100 / m.revenue_total)
                    ELSE 0 
                END as net_margin,
                CASE 
                    WHEN m.revenue_total > 0 
                    THEN ((m.revenue_total - m.revenue_channel) * 100 / m.revenue_total) -
                        ((m.revenue_total - m.revenue_channel - 
                        CASE 
                            WHEN st.id = 14 THEN (m.impressions_good / 1000 * 0.15)
                            WHEN st.id = 15 THEN (m.impressions_good / 1000 * 0.02)
                            ELSE 0 
                        END) * 100 / m.revenue_total)
                    ELSE 0 
                END as margin_difference
            FROM rtb_margin m
            JOIN advertisers a ON m.advertiser_id = a.id
            JOIN channels c ON m.channel_id = c.id
            JOIN sources s ON m.source_id = s.id
            JOIN source_types st ON m.source_type_id = st.id
            WHERE {$whereClause}
            ORDER BY m.date ASC, a.name ASC, c.name ASC, s.name ASC";

            $stmt = $this->db->prepare($query);
            $stmt->execute($queryParams);
            $data = $stmt->fetchAll();

            // Create CSV
            $output = fopen('php://temp', 'w+');

            // Add headers
            fputcsv($output, [
                'Date',
                'Advertiser',
                'Channel',
                'Source',
                'Format',
                'Avg CPM',
                'Impressions',
                'Channel Revenue',
                'Total Revenue',
                'Gross Profit',
                'Gross Margin %',
                'Cost Imps',
                'Net Profit',
                'Margin Difference %',
                'Net Margin %'
            ]);

            // Add data rows
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['date'],
                    $row['advertiser'],
                    $row['channel'],
                    $row['source'],
                    $row['source_type'],
                    number_format($row['ecpm'], 2),
                    $row['impressions'],
                    number_format($row['revenue_channel'], 2),
                    number_format($row['revenue_total'], 2),
                    number_format($row['gross_profit'], 2),
                    number_format($row['gross_margin'], 2),
                    number_format($row['cost_imps'], 2),
                    number_format($row['net_profit'], 2),
                    number_format($row['margin_difference'], 2),
                    number_format($row['net_margin'], 2)
                ]);
            }

            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);

            // Build response
            $fileName = 'margin_' . $start_date . '_to_' . $end_date . '.csv';
            $response = $response->withHeader('Content-Type', 'text/csv');
            $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
            $response->getBody()->write($csv);

            return $response;

        } catch (\Exception $e) {
            error_log("Export error: " . $e->getMessage());
            throw $e;
        }
    }
}
