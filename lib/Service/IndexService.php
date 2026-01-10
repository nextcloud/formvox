<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

class IndexService
{
    /**
     * Update index after adding a new response
     */
    public function updateIndex(array &$form, array $response, int $index): void
    {
        if (!isset($form['_index'])) {
            $this->rebuildIndex($form);
            return;
        }

        $idx = &$form['_index'];

        // Update response count
        $idx['response_count'] = ($idx['response_count'] ?? 0) + 1;
        $idx['last_response_at'] = $response['submitted_at'] ?? date('c');

        // Update fingerprint or user_id index
        if (isset($response['respondent'])) {
            if ($response['respondent']['type'] === 'anonymous' && isset($response['respondent']['fingerprint'])) {
                $fingerprint = $response['respondent']['fingerprint'];
                $idx['fingerprints'][$fingerprint] = $index;
            } elseif ($response['respondent']['type'] === 'user' && isset($response['respondent']['user_id'])) {
                $userId = $response['respondent']['user_id'];
                $idx['user_ids'][$userId] = $index;
            }
        }

        // Update by_date index
        $date = substr($response['submitted_at'] ?? date('c'), 0, 10);
        if (!isset($idx['by_date'][$date])) {
            $idx['by_date'][$date] = [];
        }
        $idx['by_date'][$date][] = $index;

        // Update answer counts
        if (isset($response['answers'])) {
            foreach ($response['answers'] as $questionId => $answer) {
                if (!isset($idx['answer_counts'][$questionId])) {
                    $idx['answer_counts'][$questionId] = [];
                }

                if (is_array($answer)) {
                    // Multiple choice
                    foreach ($answer as $val) {
                        $val = (string)$val;
                        $idx['answer_counts'][$questionId][$val] = ($idx['answer_counts'][$questionId][$val] ?? 0) + 1;
                    }
                } else {
                    $answer = (string)$answer;
                    $idx['answer_counts'][$questionId][$answer] = ($idx['answer_counts'][$questionId][$answer] ?? 0) + 1;
                }
            }
        }

        // Update checksum
        $idx['_checksum'] = $this->calculateChecksum($form['responses'] ?? []);
    }

    /**
     * Rebuild the entire index from scratch
     */
    public function rebuildIndex(array &$form): void
    {
        $form['_index'] = [
            '_checksum' => '',
            'response_count' => 0,
            'last_response_at' => null,
            'fingerprints' => [],
            'user_ids' => [],
            'by_date' => [],
            'answer_counts' => [],
        ];

        $responses = $form['responses'] ?? [];

        foreach ($responses as $index => $response) {
            $form['_index']['response_count']++;

            if (isset($response['submitted_at'])) {
                $form['_index']['last_response_at'] = $response['submitted_at'];
            }

            // Index fingerprint or user_id
            if (isset($response['respondent'])) {
                if ($response['respondent']['type'] === 'anonymous' && isset($response['respondent']['fingerprint'])) {
                    $form['_index']['fingerprints'][$response['respondent']['fingerprint']] = $index;
                } elseif ($response['respondent']['type'] === 'user' && isset($response['respondent']['user_id'])) {
                    $form['_index']['user_ids'][$response['respondent']['user_id']] = $index;
                }
            }

            // Index by date
            if (isset($response['submitted_at'])) {
                $date = substr($response['submitted_at'], 0, 10);
                if (!isset($form['_index']['by_date'][$date])) {
                    $form['_index']['by_date'][$date] = [];
                }
                $form['_index']['by_date'][$date][] = $index;
            }

            // Count answers
            if (isset($response['answers'])) {
                foreach ($response['answers'] as $questionId => $answer) {
                    if (!isset($form['_index']['answer_counts'][$questionId])) {
                        $form['_index']['answer_counts'][$questionId] = [];
                    }

                    if (is_array($answer)) {
                        foreach ($answer as $val) {
                            $val = (string)$val;
                            $form['_index']['answer_counts'][$questionId][$val] =
                                ($form['_index']['answer_counts'][$questionId][$val] ?? 0) + 1;
                        }
                    } else {
                        $answer = (string)$answer;
                        $form['_index']['answer_counts'][$questionId][$answer] =
                            ($form['_index']['answer_counts'][$questionId][$answer] ?? 0) + 1;
                    }
                }
            }
        }

        // Calculate checksum
        $form['_index']['_checksum'] = $this->calculateChecksum($responses);
    }

    /**
     * Verify index integrity
     */
    public function verifyIndex(array $form): bool
    {
        if (!isset($form['_index']) || !isset($form['_index']['_checksum'])) {
            return false;
        }

        $expectedChecksum = $this->calculateChecksum($form['responses'] ?? []);
        return $form['_index']['_checksum'] === $expectedChecksum;
    }

    /**
     * Check if a fingerprint already exists
     */
    public function hasFingerprint(array $form, string $fingerprint): bool
    {
        return isset($form['_index']['fingerprints'][$fingerprint]);
    }

    /**
     * Check if a user has already responded
     */
    public function hasUserResponse(array $form, string $userId): bool
    {
        return isset($form['_index']['user_ids'][$userId]);
    }

    /**
     * Get response count
     */
    public function getResponseCount(array $form): int
    {
        return $form['_index']['response_count'] ?? count($form['responses'] ?? []);
    }

    /**
     * Get answer statistics for a question
     */
    public function getAnswerStats(array $form, string $questionId): array
    {
        return $form['_index']['answer_counts'][$questionId] ?? [];
    }

    /**
     * Get responses by date
     */
    public function getResponsesByDate(array $form, string $date): array
    {
        $indices = $form['_index']['by_date'][$date] ?? [];
        $responses = [];

        foreach ($indices as $index) {
            if (isset($form['responses'][$index])) {
                $responses[] = $form['responses'][$index];
            }
        }

        return $responses;
    }

    /**
     * Calculate checksum for responses
     */
    private function calculateChecksum(array $responses): string
    {
        return hash('sha256', json_encode($responses));
    }
}
