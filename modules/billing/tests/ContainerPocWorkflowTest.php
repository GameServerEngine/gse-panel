<?php
require_once __DIR__ . '/../includes/container_poc.php';

function poc_assert($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$empty = [
    'application_id' => 1,
    'slug' => 'empty-container',
    'name' => 'None / Empty Container',
    'minimum_cpu' => 1,
    'minimum_memory_mb' => 512,
    'minimum_storage_gb' => 5,
    'recommended_cpu' => 1,
    'recommended_memory_mb' => 1024,
    'recommended_storage_gb' => 10,
    'required_ports_json' => '[]',
    'default_port_block_size' => 0,
    'container_image' => 'alpine:latest',
];

$errors = container_poc_validate_request($empty, [
    'container_name' => 'demo',
    'cpu' => 1,
    'memory_mb' => 1024,
    'storage_gb' => 10,
    'port_mode' => 'none',
]);
poc_assert($errors === [], 'empty container accepts optional ports');

$errors = container_poc_validate_request($empty, [
    'container_name' => 'demo',
    'cpu' => 0.5,
    'memory_mb' => 1024,
    'storage_gb' => 10,
    'port_mode' => 'none',
]);
poc_assert(in_array('CPU is below the selected application minimum.', $errors, true), 'minimum CPU enforced');

$ports = [[
    'protocol' => 'tcp',
    'label' => 'Game',
    'host_ip' => '0.0.0.0',
    'host_port' => 30000,
    'container_port' => 25565,
    'expected_listener' => true,
]];
$request = container_poc_build_container_request(42, $empty, [
    'container_name' => 'Demo Container',
    'platform' => 'linux',
    'cpu' => 2,
    'memory_mb' => 2048,
], $ports);
poc_assert($request['name'] === 'gse-42-demo-container', 'container name is normalized');
poc_assert($request['resources']['cpu_limit'] === 2.0, 'CPU payload is passed to agent');
poc_assert($request['ports'][0]['host_port'] === 30000, 'ports are passed structurally');
poc_assert($request['image']['reference'] === 'alpine:latest', 'image comes from selected application');

$calls = [];
$fakeTransport = function (array $agent, string $method, string $path, ?array $payload = null) use (&$calls): array {
    $calls[] = [$method, $path, $payload];
    if ($path === '/v1/containers') {
        return ['success' => true, 'status_code' => 201, 'body' => ['data' => ['container_id' => 'fake-runtime-1']]];
    }
    return ['success' => true, 'status_code' => 200, 'body' => ['data' => ['ok' => true]]];
};
$result = $fakeTransport(['base_url' => 'http://agent', 'api_token' => 'token'], 'POST', '/v1/containers', $request);
poc_assert($result['success'] === true, 'fake agent create succeeds');
poc_assert(count($calls) === 1, 'fake transport captured one call');

$accessResponse = [
    'body' => [
        'data' => [
            'profile' => [
                'container_id' => 'fake-runtime-1',
                'server_id' => 1,
                'username' => 'server_1',
                'host' => '10.255.0.4',
                'port' => 2222,
                'ssh_supported' => true,
                'sftp_supported' => true,
                'revoked' => false,
            ],
            'one_time_password' => 'temporary-secret',
        ],
    ],
];
$profile = container_poc_access_profile_from_response($accessResponse);
poc_assert($profile['username'] === 'server_1', 'access profile username is parsed from agent envelope');
poc_assert($profile['port'] === 2222, 'access profile port is parsed from agent envelope');
poc_assert(container_poc_access_password_from_response($accessResponse) === 'temporary-secret', 'one-time password is parsed from agent envelope');

echo "ContainerPocWorkflowTest passed\n";
