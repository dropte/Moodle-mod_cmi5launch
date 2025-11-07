<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_cmi5launch\local;

defined('MOODLE_INTERNAL') || die();

/**
 * AI Insights Generator
 *
 * @package mod_cmi5launch
 * @copyright 2025
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_insights {

    /**
     * Generate insights based on type and data
     *
     * @param string $insighttype Type of insight to generate
     * @param object $cmi5launch CMI5 launch instance
     * @param array $enrolledusers Array of enrolled users
     * @param object $DB Database object
     * @return string Generated insights
     */
    public static function generate_insights($insighttype, $cmi5launch, $enrolledusers, $DB) {
        global $CFG;

        // Get AI configuration
        $provider = get_config('cmi5launch', 'ai_provider');
        $apikey = get_config('cmi5launch', 'ai_api_key');
        $model = get_config('cmi5launch', 'ai_model');
        $endpoint = get_config('cmi5launch', 'ai_local_endpoint');

        if (empty($provider) || ($provider !== 'local' && empty($apikey))) {
            throw new \moodle_exception('AI provider not configured properly');
        }

        // Collect data based on insight type
        $data = self::collect_data($insighttype, $cmi5launch, $enrolledusers, $DB);

        // Generate prompt
        $prompt = self::generate_prompt($insighttype, $data);

        // Call AI provider
        switch ($provider) {
            case 'openai':
                return self::call_openai($prompt, $apikey, $model);
            case 'claude':
                return self::call_claude($prompt, $apikey, $model);
            case 'local':
                return self::call_local_llm($prompt, $endpoint, $model);
            default:
                throw new \moodle_exception('Invalid AI provider');
        }
    }

    /**
     * Collect data for insights
     */
    private static function collect_data($insighttype, $cmi5launch, $enrolledusers, $DB) {
        $data = [
            'activity_name' => $cmi5launch->name,
            'total_users' => count($enrolledusers),
            'insight_type' => $insighttype
        ];

        // Get user course data
        $usercourses = $DB->get_records('cmi5launch_usercourse', ['moodlecourseid' => $cmi5launch->id]);

        $started = 0;
        $completed = 0;
        $inprogress = 0;
        $userdetails = [];

        foreach ($enrolledusers as $user) {
            $usercourse = $DB->get_record('cmi5launch_usercourse', [
                'moodlecourseid' => $cmi5launch->id,
                'userid' => $user->id
            ]);

            if ($usercourse) {
                $started++;
                $aus = json_decode($usercourse->aus);
                $totalaus = $aus ? count($aus) : 0;
                $completedaus = 0;

                if ($aus && is_array($aus)) {
                    foreach ($aus as $auid) {
                        $au = $DB->get_record('cmi5launch_aus', ['id' => $auid]);
                        if ($au && $au->satisfied === 'Satisfied') {
                            $completedaus++;
                        }
                    }
                }

                if ($totalaus > 0 && $completedaus == $totalaus) {
                    $completed++;
                } else if ($completedaus > 0) {
                    $inprogress++;
                }

                $userdetails[] = [
                    'userid' => $user->id,
                    'username' => fullname($user),
                    'started' => true,
                    'completed_aus' => $completedaus,
                    'total_aus' => $totalaus,
                    'last_access' => $usercourse->timemodified ?? 0
                ];
            } else {
                $userdetails[] = [
                    'userid' => $user->id,
                    'username' => fullname($user),
                    'started' => false,
                    'completed_aus' => 0,
                    'total_aus' => 0,
                    'last_access' => 0
                ];
            }
        }

        $data['started'] = $started;
        $data['completed'] = $completed;
        $data['inprogress'] = $inprogress;
        $data['not_started'] = $data['total_users'] - $started;
        $data['user_details'] = $userdetails;

        // Get session data
        $sessions = $DB->get_records('cmi5launch_sessions', ['moodlecourseid' => $cmi5launch->id]);
        $data['total_sessions'] = count($sessions);

        // Get unique users with sessions
        $uniqueusers = $DB->get_records_sql(
            "SELECT DISTINCT userid FROM {cmi5launch_sessions} WHERE moodlecourseid = ?",
            [$cmi5launch->id]
        );
        $data['active_users'] = count($uniqueusers);

        // Get AU data
        if (!empty($cmi5launch->aus)) {
            $aus = json_decode($cmi5launch->aus);
            $audata = [];
            if ($aus && is_array($aus)) {
                foreach ($aus as $index => $auinfo) {
                    $au = is_array($auinfo) && count($auinfo) > 0 ? $auinfo[0] : $auinfo;
                    $title = 'Activity ' . ($index + 1);
                    if (is_object($au) && isset($au->title)) {
                        if (is_array($au->title) && count($au->title) > 0) {
                            $titleobj = $au->title[0];
                            if (is_object($titleobj) && isset($titleobj->text)) {
                                $title = $titleobj->text;
                            }
                        }
                    }
                    $audata[] = [
                        'index' => $index,
                        'title' => $title
                    ];
                }
            }
            $data['activities'] = $audata;
        }

        return $data;
    }

    /**
     * Generate prompt for AI
     */
    private static function generate_prompt($insighttype, $data) {
        $prompt = "You are an educational data analyst. Analyze the following CMI5 learning activity data and provide insights.\n\n";
        $prompt .= "Activity: {$data['activity_name']}\n";
        $prompt .= "Total Users: {$data['total_users']}\n";
        $prompt .= "Started: {$data['started']}\n";
        $prompt .= "In Progress: {$data['inprogress']}\n";
        $prompt .= "Completed: {$data['completed']}\n";
        $prompt .= "Not Started: {$data['not_started']}\n";
        $prompt .= "Total Sessions: {$data['total_sessions']}\n";
        $prompt .= "Active Users: {$data['active_users']}\n\n";

        switch ($insighttype) {
            case 'progress':
                $prompt .= "Provide an overall progress analysis. Include:\n";
                $prompt .= "1. Completion rate assessment\n";
                $prompt .= "2. Engagement level (started vs enrolled)\n";
                $prompt .= "3. Progress trends\n";
                $prompt .= "4. Key observations\n";
                $prompt .= "5. Recommendations for improvement\n";
                break;

            case 'engagement':
                $prompt .= "Analyze user engagement patterns. Include:\n";
                $prompt .= "1. Session frequency analysis\n";
                $prompt .= "2. User activity levels\n";
                $prompt .= "3. Engagement trends\n";
                $prompt .= "4. Dropout points\n";
                $prompt .= "5. Recommendations to increase engagement\n";
                break;

            case 'recommendations':
                $prompt .= "Provide learning recommendations. Include:\n";
                $prompt .= "1. Content optimization suggestions\n";
                $prompt .= "2. Pacing recommendations\n";
                $prompt .= "3. Support strategies for struggling users\n";
                $prompt .= "4. Ways to improve completion rates\n";
                $prompt .= "5. Next steps for instructors\n";
                break;

            case 'at_risk':
                $notstartedcount = 0;
                $stalledcount = 0;
                $now = time();
                $weekago = $now - (7 * 24 * 60 * 60);

                foreach ($data['user_details'] as $user) {
                    if (!$user['started']) {
                        $notstartedcount++;
                    } else if ($user['last_access'] > 0 && $user['last_access'] < $weekago &&
                              $user['completed_aus'] < $user['total_aus']) {
                        $stalledcount++;
                    }
                }

                $prompt .= "Identify at-risk users. Include:\n";
                $prompt .= "1. Users who haven't started: {$notstartedcount}\n";
                $prompt .= "2. Users with stalled progress (>7 days inactive): {$stalledcount}\n";
                $prompt .= "3. Risk level assessment\n";
                $prompt .= "4. Intervention strategies\n";
                $prompt .= "5. Priority actions for instructors\n";
                break;
        }

        $prompt .= "\nProvide a clear, actionable analysis in 3-5 paragraphs. Use bullet points for key findings.";

        return $prompt;
    }

    /**
     * Call OpenAI API
     */
    private static function call_openai($prompt, $apikey, $model = null) {
        if (empty($model)) {
            $model = 'gpt-4o-mini';
        }

        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 1000
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apikey
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            throw new \moodle_exception('OpenAI API error: HTTP ' . $httpcode . ' - ' . $response);
        }

        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        }

        throw new \moodle_exception('Unexpected OpenAI API response format');
    }

    /**
     * Call Claude API
     */
    private static function call_claude($prompt, $apikey, $model = null) {
        if (empty($model)) {
            $model = 'claude-3-5-sonnet-20241022';
        }

        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 1024
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $apikey,
            'anthropic-version: 2023-06-01'
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            throw new \moodle_exception('Claude API error: HTTP ' . $httpcode . ' - ' . $response);
        }

        $result = json_decode($response, true);
        if (isset($result['content'][0]['text'])) {
            return $result['content'][0]['text'];
        }

        throw new \moodle_exception('Unexpected Claude API response format');
    }

    /**
     * Call Local LLM
     */
    private static function call_local_llm($prompt, $endpoint, $model = null) {
        if (empty($endpoint)) {
            throw new \moodle_exception('Local LLM endpoint not configured');
        }

        // Support for Ollama-style API
        $data = [
            'model' => $model ?: 'llama2',
            'prompt' => $prompt,
            'stream' => false
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            throw new \moodle_exception('Local LLM error: HTTP ' . $httpcode . ' - ' . $response);
        }

        $result = json_decode($response, true);
        if (isset($result['response'])) {
            return $result['response'];
        }

        throw new \moodle_exception('Unexpected Local LLM response format');
    }
}
