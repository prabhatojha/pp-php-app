<?php
require __DIR__ . '/../vendor/autoload.php';

/**
 * - Run the web server
 * - Run this server.php
 * - Make API call from FE
 * - Create new repo
 */
use GuzzleHttp\Client;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$PAYPAL_CLIENT_ID = $_ENV['PAYPAL_CLIENT_ID'];
$PAYPAL_CLIENT_SECRET = $_ENV['PAYPAL_CLIENT_SECRET'];
$PORT = $_ENV['PORT'] ?? 8888;
$base = "https://api-m.sandbox.paypal.com";
$app = AppFactory::create();

// host static files
$app->get('/', function ($request, $response, $args) {
    $response->withHeader('Content-Type', 'text/html');
    $response->getBody()->write(file_get_contents('checkout.html'));
    return $response;
});

// parse post params sent in body in json format
$app->addBodyParsingMiddleware();

/**
 * Generate an OAuth 2.0 access token for authenticating with PayPal REST APIs.
 * @see https://developer.paypal.com/api/rest/authentication/
 */
function generateAccessToken() {
    global $PAYPAL_CLIENT_ID, $PAYPAL_CLIENT_SECRET, $base;
    try {
        if (!$PAYPAL_CLIENT_ID || !$PAYPAL_CLIENT_SECRET) {
            throw new Exception("MISSING_API_CREDENTIALS");
        }
        $auth = base64_encode($PAYPAL_CLIENT_ID . ":" . $PAYPAL_CLIENT_SECRET);
        $client = new Client();
        $response = $client->request('POST', "$base/v1/oauth2/token", [
            'headers' => [
                'Authorization' => "Basic $auth",
            ],
            'form_params' => [
                'grant_type' => 'client_credentials',
            ],
        ]);
        $data = json_decode($response->getBody(), true);
        return $data['access_token'];
    } catch (Exception $e) {
        error_log("Failed to generate Access Token: " . $e->getMessage());
    }
}

function handleResponse($response) {
    try {
        $jsonResponse = json_decode($response->getBody(), true);
        return [
            'jsonResponse' => $jsonResponse,
            'httpStatusCode' => $response->getStatusCode(),
        ];
    } catch (Exception $e) {
        $errorMessage = $response->getBody();
        throw new Exception($errorMessage);
    }
}

/**
 * Create an order to start the transaction.
 * @see https://developer.paypal.com/docs/api/orders/v2/#orders_create
 */
function createOrder($cart) {
    global $base;
    // use the cart information passed from the front-end to calculate the purchase unit details
    error_log("shopping cart information passed from the frontend createOrder() callback: " . print_r($cart, true));

    $accessToken = generateAccessToken();
    $url = "$base/v2/checkout/orders";

    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [
            [
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => '100',
                ],
            ],
        ],
    ];

    $client = new Client();
    $response = $client->request('POST', $url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer $accessToken",
            // Uncomment one of these to force an error for negative testing (in sandbox mode only).
            // Documentation: https://developer.paypal.com/tools/sandbox/negative-testing/request-headers/
            // 'PayPal-Mock-Response' => '{"mock_application_codes": "MISSING_REQUIRED_PARAMETER"}'
            // 'PayPal-Mock-Response' => '{"mock_application_codes": "PERMISSION_DENIED"}'
            // 'PayPal-Mock-Response' => '{"mock_application_codes": "INTERNAL_SERVER_ERROR"}'
        ],
        'json' => $payload,
    ]);

    return handleResponse($response);
}

// createOrder route
$app->post('/api/orders', function ($request, $response, $args) {
    try {
        // use the cart information passed from the front-end to calculate the order amount detals
        $cart = $request->getParsedBody()['cart'];
        $result = createOrder($cart);
        $response->getBody()->write(json_encode($result['jsonResponse']));
        return $response->withStatus($result['httpStatusCode']);
    } catch (Exception $e) {
        error_log("Failed to create order: " . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to create order.']);
    }
});

$app->run();

