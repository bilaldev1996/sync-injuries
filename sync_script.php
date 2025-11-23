<?php

$API_HOST = 'v3.football.api-sports.io';
$API_KEY = '7992e9a30d847ca75d9db20c2f93b49b';
$LARAVEL_ENDPOINT = 'https://panel.intelibetia.com/sync-injuries';

// Ligas a sincronizar
// $leagues = [2, 140, 78, 61, 39, 135, 3];
$leagues = [140];
$season = date('Y');

/* ====================================================
   FUNCIÓN GENERAL PARA LLAMAR API SPORTS
==================================================== */
function fetchData($url, $host, $key) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "x-rapidapi-host: {$host}",
        "x-rapidapi-key: {$key}",
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/* ====================================================
   OBTENER INJURIES DE UN EQUIPO
==================================================== */
function fetchInjuriesByTeam($teamId, $season) {
    global $API_HOST, $API_KEY;
    $url = "https://{$API_HOST}/injuries?team={$teamId}&season={$season}";
    $data = fetchData($url, $API_HOST, $API_KEY);
    return $data['response'] ?? [];
}

/* ====================================================
   OBTENER FIXTURE COMPLETO POR ID (para home/away)
==================================================== */
function fetchFixtureFull($fixtureId) {
    global $API_HOST, $API_KEY;
    $url = "https://{$API_HOST}/fixtures?id={$fixtureId}";
    $data = fetchData($url, $API_HOST, $API_KEY);
    return $data['response'][0] ?? null;
}

/* ====================================================
   OBTENER EQUIPOS DE UNA LIGA → STANDINGS
==================================================== */
function fetchTeamsByLeague($leagueId, $season) {
    global $API_HOST, $API_KEY;

    $url = "https://{$API_HOST}/standings?league={$leagueId}&season={$season}";
    $data = fetchData($url, $API_HOST, $API_KEY);

    if (!isset($data['response'][0]['league']['standings'])) return [];

    $teams = [];
    foreach ($data['response'][0]['league']['standings'] as $group) {
        foreach ($group as $team) {
            $teams[] = $team['team']['id'];
        }
    }
    return array_unique($teams);
}

/* ====================================================
   ENVIAR INJURIES A LARAVEL
==================================================== */
function sendInjuriesToLaravel($injuries, $endpoint) {
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['injuries' => $injuries]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/* ====================================================
   MAIN SCRIPT → OBTENER TODO Y ENVIAR A LARAVEL
==================================================== */

try {
    $allInjuries = [];
    $now = time();

    foreach ($leagues as $leagueId) {

        echo "Procesando liga {$leagueId} season {$season}\n";

        // Obtener equipos de la liga
        $teamIds = fetchTeamsByLeague($leagueId, $season);

        foreach ($teamIds as $teamId) {

            echo "Buscando lesiones para equipo {$teamId}\n";

            $injuries = fetchInjuriesByTeam($teamId, $season);

            foreach ($injuries as $injury) {

                // Saltar si la fecha no es futura
                if (!isset($injury['fixture']['date'])) continue;

                $fixtureDate = strtotime($injury['fixture']['date']);
                if ($fixtureDate <= $now) continue;

                // Obtener fixture completo
                $fx = fetchFixtureFull($injury['fixture']['id']);
                if (!$fx) continue;

                // Añadir datos obligatorios home/away
                $injury['home_team_id']   = $fx['teams']['home']['id'];
                $injury['home_team_name'] = $fx['teams']['home']['name'];
                $injury['away_team_id']   = $fx['teams']['away']['id'];
                $injury['away_team_name'] = $fx['teams']['away']['name'];

                $allInjuries[] = $injury;
            }
        }
    }

    // Enviar a Laravel
    $result = sendInjuriesToLaravel($allInjuries, $LARAVEL_ENDPOINT);
    echo json_encode(['message' => $result['message'] ?? 'Lesiones enviadas correctamente.']);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    error_log("Error: " . $e->getMessage());
}

?>
