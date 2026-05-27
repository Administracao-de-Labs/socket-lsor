<?php

$ch = curl_init('http://localhost:4000/api/v1/events');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('event' => 'NEW_EVENT', 'channel' => 'show')));
$response = curl_exec($ch);
curl_close($ch);

var_dump($response);