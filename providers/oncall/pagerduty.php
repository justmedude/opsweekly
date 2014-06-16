<?php

/**
 * Pagerduty on call provider
 */

/** Plugin specific variables required
 * Global Config:
 *  - base_url: The path to your Pagerduty API, e.g. https://company.pagerduty.com/api/v1
 *  - username: A user that can access your Pagerduty account using the API
 *  - password: The password for the user above
 *
 * Team Config:
 *  - pagerduty_service_id: The service ID that this team uses for alerts to be collected
 *
 */


/**
 * getOnCallNotifications - Returns the notifications for a given time period and parameters
 *
 * Parameters:
 *   $on_call_name - The username of the user compiling this report
 *   $provider_global_config - All options from config.php in $oncall_providers - That is, global options.
 *   $provider_team_config - All options from config.php in $teams - That is, specific team configuration options
 *   $start - The unix timestamp of when to start looking for notifications
 *   $end - The unix timestamp of when to stop looking for notifications
 *
 * Returns 0 or more notifications as array()
 * - Each notification should have the following keys:
 *    - time: Unix timestamp of when the alert was sent to the user
 *    - hostname: Ideally contains the hostname of the problem. Must be populated but feel free to make bogus if not applicable.
 *    - service: Contains the service name or a description of the problem. Must be populated. Perhaps use "Host Check" for host alerts.
 *    - output: The plugin output, e.g. from Nagios, describing the issue so the user can reference easily/remember issue
 *    - state: The level of the problem. One of: CRITICAL, WARNING, UNKNOWN, DOWN
 */

function getOnCallNotifications($name, $global_config, $team_config, $start, $end) {

    $base_url = $global_config['base_url'];
    $username = $global_config['username'];
    $password = $global_config['password'];
    $service_id = $team_config['pagerduty_service_id'];

    if ($base_url !== '' && $username !== '' && $password !== '' && $service_id !== '') {

        // Connect to the Pagerduty API and collect all incidents in the time period.
        $parameters = array(
            'since' => date('c', $start),
            'service' => $service_id,
            'until' => date('c', $end),
        );

        $incident_json = doPagerdutyAPICall('/incidents', $parameters, $base_url, $username, $password);
        if (!$incidents = json_decode($incident_json)) {
            return 'Could not retrieve incidents from Pagerduty! Please check your login details';
        }
        if (count($incidents->incidents) == 0) {
            return array();
        }
        foreach ($incidents->incidents as $incident) {
            $time = strtotime($incident->created_on);
            $service = $incident->trigger_summary_data->subject;
            $output = $incident->trigger_details_html_url;

            $notifications[] = array("time" => $time, "hostname" => "Pagerduty", "service" => $service, "output" => $output, "state" => "CRITICAL");
        }
        return $notifications;

    } else {
        return false;
    }

}

function doPagerdutyAPICall($path, $parameters, $pagerduty_baseurl, $pagerduty_username, $pagerduty_password) {

    $context = stream_context_create(array(
        'http' => array(
            'header'  => "Authorization: Basic " . base64_encode("$pagerduty_username:$pagerduty_password")
        )
    ));

    $params = null;
    foreach ($parameters as $key => $value) {
        if (isset($params)) {
            $params .= '&';
        } else {
            $params = '?';
        }
        $params .= sprintf('%s=%s', $key, $value);
    }
    return file_get_contents($pagerduty_baseurl . $path . $params, false, $context);
}

function whoIsOnCall($schedule_id, $time = null) {

    $until = $since = date('c', isset($time) ? $time : time());
    $parameters = array(
        'since' => $since,
        'until' => $until,
        'overflow' => 'true',
    );

    $json = doPagerdutyAPICall("/schedules/{$schedule_id}/entries", $parameters);

    if (false === ($scheddata = json_decode($json))) {
        return false;
    }

    if ($scheddata->total == 0) {
        return false;
    }

    if ($scheddata->entries['0']->user->name == "") {
        return false;
    }

    $oncalldetails = array();
    $oncalldetails['person'] = $scheddata->entries['0']->user->name;
    $oncalldetails['email'] = $scheddata->entries['0']->user->email;
    $oncalldetails['start'] = strtotime($scheddata->entries['0']->start);
    $oncalldetails['end'] = strtotime($scheddata->entries['0']->end);

    return $oncalldetails;

}

?>
