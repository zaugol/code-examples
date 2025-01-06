<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
use PDO;

class StatsController
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
        // Get advertisers for dropdown
        $advertisers = $this->db->query("SELECT id, name FROM advertisers ORDER BY name")->fetchAll();

        // Get parameters
        $params = $request->getQueryParams();
        $advertiser_id = $params['advertiser_id'] ?? 'all';
        $start_date = $params['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $end_date = $params['end_date'] ?? date('Y-m-d');

        // Build WHERE clause
        $where = ['d1.date BETWEEN :start_date AND :end_date'];
        $params = [
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ];

        if ($advertiser_id !== 'all') {
            $where[] = 'd1.advertiser = :advertiser_id';
            $params[':advertiser_id'] = $advertiser_id;
        }

        $whereClause = implode(' AND ', $where);

        $query = "WITH daily_data AS (
            SELECT 
                d1.date,
                d1.ad_requests as rtb_requests,
                d2.ad_requests as opera_requests,
                ROUND(((d2.ad_requests - d1.ad_requests) / d1.ad_requests * 100), 2) as requests_diff_percent,
                d1.impressions as rtb_impressions,
                d2.impressions as opera_impressions,
                ROUND(((d2.impressions - d1.impressions) / d1.impressions * 100), 2) as impressions_diff_percent,
                ROUND(d1.revenue, 2) as rtb_revenue,
                ROUND(d2.revenue, 2) as opera_revenue,
                ROUND(((d2.revenue - d1.revenue) / d1.revenue * 100), 2) as revenue_diff_percent,
                ROUND((d2.revenue - d1.revenue), 2) as revenue_diff
            FROM daily_stats d1
            LEFT JOIN daily_stats d2 ON d1.date = d2.date 
                AND d1.advertiser = d2.advertiser 
                AND d2.source = 1
            WHERE d1.source = 0 AND {$whereClause}
        )
        SELECT * FROM (
            SELECT 
                date,
                rtb_requests,
                opera_requests,
                requests_diff_percent,
                rtb_impressions,
                opera_impressions,
                impressions_diff_percent,
                rtb_revenue,
                opera_revenue,
                revenue_diff_percent,
                revenue_diff
            FROM daily_data
            
            UNION ALL
            
            SELECT 
                'Total' as date,
                SUM(rtb_requests) as rtb_requests,
                SUM(opera_requests) as opera_requests,
                ROUND(((SUM(opera_requests) - SUM(rtb_requests)) / SUM(rtb_requests) * 100), 2) as requests_diff_percent,
                SUM(rtb_impressions) as rtb_impressions,
                SUM(opera_impressions) as opera_impressions,
                ROUND(((SUM(opera_impressions) - SUM(rtb_impressions)) / SUM(rtb_impressions) * 100), 2) as impressions_diff_percent,
                ROUND(SUM(rtb_revenue), 2) as rtb_revenue,
                ROUND(SUM(opera_revenue), 2) as opera_revenue,
                ROUND(((SUM(opera_revenue) - SUM(rtb_revenue)) / SUM(rtb_revenue) * 100), 2) as revenue_diff_percent,
                ROUND(SUM(revenue_diff), 2) as revenue_diff
            FROM daily_data
        ) result
        ORDER BY date = 'Total', date ASC";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $stats = $stmt->fetchAll();

        return $this->view->render($response, 'stats/index.php', [
            'advertisers' => $advertisers,
            'stats' => $stats,
            'advertiser_id' => $advertiser_id,
            'start_date' => $start_date,
            'end_date' => $end_date
        ]);
    }

    public function export(Request $request, Response $response): Response
    {
        // Get parameters
        $params = $request->getQueryParams();
        $advertiser_id = $params['advertiser_id'] ?? 'all';
        $start_date = $params['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $end_date = $params['end_date'] ?? date('Y-m-d');

        // Build WHERE clause
        $where = ['d1.date BETWEEN :start_date AND :end_date'];
        $queryParams = [
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ];

        if ($advertiser_id !== 'all') {
            $where[] = 'd1.advertiser = :advertiser_id';
            $queryParams[':advertiser_id'] = $advertiser_id;
        }

        $whereClause = implode(' AND ', $where);

        // Existing query
        $query = "WITH daily_data AS (
            SELECT 
                d1.date,
                d1.ad_requests as rtb_requests,
                d2.ad_requests as opera_requests,
                ((d2.ad_requests - d1.ad_requests) / d1.ad_requests * 100) as requests_diff_percent,
                d1.impressions as rtb_impressions,
                d2.impressions as opera_impressions,
                ((d2.impressions - d1.impressions) / d1.impressions * 100) as impressions_diff_percent,
                d1.revenue as rtb_revenue,
                d2.revenue as opera_revenue,
                ((d2.revenue - d1.revenue) / d1.revenue * 100) as revenue_diff_percent,
                (d2.revenue - d1.revenue) as revenue_diff
            FROM daily_stats d1
            LEFT JOIN daily_stats d2 ON d1.date = d2.date 
                AND d1.advertiser = d2.advertiser 
                AND d2.source = 1
            WHERE d1.source = 0 AND {$whereClause}
        )
        SELECT * FROM daily_data
        ORDER BY date ASC";

        $stmt = $this->db->prepare($query);
        $stmt->execute($queryParams);
        $stats = $stmt->fetchAll();

        // Create CSV
        $output = fopen('php://temp', 'w+');

        // Add headers
        fputcsv($output, [
            'Date',
            'RTBnova Requests',
            'Opera Requests',
            'Requests Diff %',
            'RTBnova Impressions',
            'Opera Impressions',
            'Impressions Diff %',
            'RTBnova Revenue',
            'Opera Revenue',
            'Revenue Diff %',
            'Revenue Diff'
        ]);

        // Add data rows
        foreach ($stats as $row) {
            fputcsv($output, [
                $row['date'],
                $row['rtb_requests'],
                $row['opera_requests'],
                number_format($row['requests_diff_percent'], 2),
                $row['rtb_impressions'],
                $row['opera_impressions'],
                number_format($row['impressions_diff_percent'], 2),
                number_format($row['rtb_revenue'], 2),
                number_format($row['opera_revenue'], 2),
                number_format($row['revenue_diff_percent'], 2),
                number_format($row['revenue_diff'], 2)
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        // Build response
        $fileName = 'stats_' . $start_date . '_to_' . $end_date . '.csv';
        $response = $response->withHeader('Content-Type', 'text/csv');
        $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $response->getBody()->write($csv);

        return $response;
    }
}
