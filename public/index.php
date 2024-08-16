<?php
declare(strict_types=1);

use DI\Container;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Twilio\Rest\Client;
use Twilio\Rest\Events\V1\SinkInstance;
use Twilio\Rest\Events\V1\SubscriptionInstance;
use Twilio\Security\RequestValidator;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/../");
$dotenv->load();
$dotenv
    ->required(['TWILIO_ACCOUNT_SID', 'TWILIO_AUTH_TOKEN'])
    ->notEmpty();

$container = new Container();
$container->set(
    Client::class,
    fn () => new Client($_ENV["TWILIO_ACCOUNT_SID"], $_ENV["TWILIO_AUTH_TOKEN"])
);

$container->set(
    LoggerInterface::class,
    fn () => (new Logger('name'))
        ->pushHandler(
            new StreamHandler(
                __DIR__ . "/../data/logs/app.log",
                Level::Debug
            )
        )
);

AppFactory::setContainer($container);
$app = AppFactory::create();

// Required to parse posted JSON, form, or XML data
$app->addBodyParsingMiddleware();

$twilio = new Client($_ENV["TWILIO_ACCOUNT_SID"], $_ENV["TWILIO_AUTH_TOKEN"]);

/**
 * This function returns an array of event types (along with their schema versions) to subscribe to
 * It handles requests to subscribe to single or multiple events.
 *
 * @return array[]
 * @see https://www.twilio.com/docs/events/webhook-quickstart?display=embedded#subscribe-to-twilio-events
 */
function getEventSubscriptionTypes(string|array $eventTypes): array
{
    return (is_array($eventTypes))
        ? [
            array_map(fn (string $type) => [
                "type" => $type,
                "schema_version" => "1"
            ], $eventTypes)
        ]
        : [
            [
                "type" => $eventTypes,
                "schema_version" => "1"
            ],
        ];
}

/**
 * This route returns a list of active subscriptions for a sink
 */
$app->get('/sink/{sid}/subscriptions', function (Request $request, Response $response, array $args) {
    /** @var Client $twilio */
    $twilio = $this->get(Client::class);
    $subscriptions = $twilio
        ->events
        ->v1
        ->subscriptions
        ->read([], 20);

    $response->withHeader('Content-Type', 'application/json');
    $response
        ->getBody()
        ->write(
            json_encode(
                [
                    "status" => "success",
                    "subscriptions" => array_map(
                        fn (SubscriptionInstance $subscription) => $subscription->sid,
                        $subscriptions
                    )
                ]
            )
        );

    return $response;
});

$app->delete('/subscription/{sid}', function (Request $request, Response $response, array $args) {
    /** @var Client $twilio */
    $twilio = $this->get(Client::class);
    $result = $twilio
        ->events
        ->v1
        ->subscriptions($args['sid'])
        ->delete();

    $response->withHeader('Content-Type', 'application/json');
    $response
        ->getBody()
        ->write(
            json_encode(
                [
                    "status" => $result ? "Subscription was deleted" : "Subscription was NOT deleted",
                ]
            )
        );

    return $response;
});

$app->get('/subscription/{sid}', function (Request $request, Response $response, array $args) {
    /** @var Client $twilio */
    $twilio = $this->get(Client::class);
    $result = $twilio
        ->events
        ->v1
        ->subscriptions($args['sid'])
        ->fetch();

    $response->withHeader('Content-Type', 'application/json');
    $response
        ->getBody()
        ->write(
            json_encode($result->toArray())
        );

    return $response;
});

/**
 * This route allows the application to subscribe to one or more events, delivering them to the
 * nominated sink id (sid).
 */
$app->post('/event/subscribe/{sid}', function (Request $request, Response $response, array $args) {
    $postData = $request->getParsedBody();

    /** @var Client $twilio */
    $twilio = $this->get(Client::class);
    $subscription = $twilio
        ->events
        ->v1
        ->subscriptions
        ->create(
            $postData['description'],
            $args['sid'],
            getEventSubscriptionTypes($postData['type'])
        );

    $response->withHeader('Content-Type', 'application/json');
    $response
        ->getBody()
        ->write(
            json_encode(
                [
                    "status" => "success",
                    [
                        "subscription-sid" => $subscription->sid,
                    ]
                ]
            )
        );

    return $response;
});

/**
 * This route attempts to create a Sink Resource on your Twilio account,
 * so that event streams are sent to the application's <<X>> route.
 *
 * @see https://www.twilio.com/docs/events/event-streams/sink-resource
 */
$app->get('/create-sink', function (Request $request, Response $response, array $args): Response {
    // Automatically determine the ngrok URL
    /**$path = parse_url((string)$request->getUri(), PHP_URL_PATH);
    $port = parse_url((string)$request->getUri(), PHP_URL_PORT);
    $uri = str_replace(
        [$path, ":{$port}"],
        '',
        (string)$request->getUri()
    );*/
    $sink = $this->get(Client::class)
        ->events
        ->v1
        ->sinks
        ->create(
            $_ENV["TWILIO_SINK_DESCRIPTION"],
            [
                "destination" => "{$_ENV["NGROK_URL"]}/webhook-sink",
                "method" => "POST"
            ],
            "webhook"
        );

    $response->withHeader("Content-Type", "application/json");
    $response
        ->getBody()
        ->write(
            json_encode([
                'created' => $sink->dateCreated?->format("r"),
                'sid' => $sink->sid,
                'status' => $sink->status,
            ])
        );

    return $response;
});

/**
 * This route is where subscribed events are delivered to the application by Twilio
 */
$app->post('/webhook-sink', function (Request $request, Response $response, array $args) {
    /** @var LoggerInterface $logger */
    $logger = $this->get(LoggerInterface::class);

    $validator = new RequestValidator($_ENV["TWILIO_AUTH_TOKEN"]);
    $isFromTwilio = $validator->validate(
        $request->getHeaderLine('X-Twilio-Signature'),
        (string)$request->getUri(),
        $request->getParsedBody()
    );

    // validate the signature
    ($isFromTwilio)
        ? $logger->info("Valid signature. Processing event.")
        : $logger->info(
        "Invalid signature.",
        [
            'Twilio Signature' => $request->getHeaderLine('X-Twilio-Signature'),
            'Request URI' => (string)$request->getUri(),
            'Event Data' => $request->getParsedBody(),
        ]
    );

    $logger->info(
        "Event received",
        $request->getParsedBody(),
    );
    $logger->info(
        "Request headers",
        [
            $request->getHeaders(),
        ]
    );

    return $response;
});

/**
 * This route deletes a sink identified by the sink id (sid). The response will contain a JSON body
 * indicating whether the sink was deleted or not.
 */
$app->delete('/sink/{sid}', function (Request $request, Response $response, array $args) {
    $sid = (string) $args['sid'];
    /** @var Client $twilio */
    $twilio = $this->get(Client::class);
    $result = $twilio
        ->events
        ->v1
        ->sinks($sid)
        ->delete();

    $response->withHeader('Content-Type', 'application/json');
    $response
        ->getBody()
        ->write(
            json_encode(
                [
                    "status" => $result ? "Sink was deleted" : "Sink was NOT deleted",
                ]
            )
        );

    return $response;
});

/**
 * This route retrieves a list of all sink resources on the user's account, and return the list
 * in a JSON array.
 */
$app->get('/sinks', function (Request $request, Response $response, array $args) {
    /** @var Client $twilio */
    $twilio = $this->get(Client::class);
    $sinks = $twilio
        ->events
        ->v1
        ->sinks
        ->read([], 20);
    $response->withHeader('Content-Type', 'application/json');
    $response
        ->getBody()
        ->write(
            json_encode(
                [
                    "status" => "success",
                    "sinks" => array_map(
                        fn (SinkInstance $sink) => $sink->sid,
                        $sinks
                    ),
                ]
            )
        );

    return $response;
});

$app->get('/sink/{sid}', function (Request $request, Response $response, array $args) {
    /** @var Client $twilio */
    $twilio = $this->get(Client::class);
    $sink = $twilio
        ->events
        ->v1
        ->sinks($args['sid'])
        ->fetch();
    $response->withHeader('Content-Type', 'application/json');
    $response
        ->getBody()
        ->write(
            json_encode(
                [
                    "status" => "success",
                    "sink" => $sink->toArray(),
                ]
            )
        );

    return $response;
});

$app->run();