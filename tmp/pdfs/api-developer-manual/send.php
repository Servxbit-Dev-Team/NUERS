<?php
$key = getenv('NUERS_API_KEY');
if (!$key) throw new RuntimeException('Key is required');
$raw = file_get_contents('event.json');
if ($raw === false) throw new RuntimeException('File missing');
$event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$ch = curl_init('https://nuers.net/api/integrations/transactions');
curl_setopt_array($ch, [
  CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
  CURLOPT_FOLLOWLOCATION => false,
  CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_HTTPHEADER => ['Accept: application/json',
    'Content-Type: application/json', 'X-NUERS-API-Key: '.$key]
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
if ($body === false) throw new RuntimeException('Keep pending');
$b = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
$valid = in_array($status, [200, 201], true) && is_array($b)
  && ($b['status'] ?? null) === 'durably_recorded'
  && ($b['environment'] ?? null) === getenv('NUERS_ENVIRONMENT')
  && ($b['source_event_id'] ?? null) === $event['source_event_id']
  && ($b['external_reference'] ?? null) === $event['external_reference']
  && is_string($b['id'] ?? null) && trim($b['id']) !== ''
  && is_string($b['receipt_id'] ?? null)
  && trim($b['receipt_id']) !== '';
if (!$valid) throw new RuntimeException('Keep pending/review');
// Commit IDs + accepted state in ONE source DB transaction.
echo json_encode(['accepted' => true, 'id' => $b['id']]);
