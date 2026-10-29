<?php
class Response {
    private static $response;
    private static $responseException;
    private static $psrResponse;

    public static function init($psrResponse) {
        self::$psrResponse = $psrResponse;
    }

    public static function getPsrResponse() {
        return self::$psrResponse;
    }

    public static function respondError($errorMsg, $exception = null, $data = null) {
        self::$response = array(
            'status' => 'fail',
            'message' => $errorMsg,
            'data' => $data
        );
        self::$responseException = $exception;

        self::writeJson(self::$response);
    }

    public static function respondSuccess($data = null) {
        self::$response = array(
            'status' => 'success',
            'data' => $data
        );

        self::writeJson(self::$response);
    }

    public static function respond403() {
        self::$psrResponse = self::$psrResponse->withStatus(403);
    }

    private static function writeJson($data) {
        // Some controllers (e.g. ticket/create.php via SignUpController)
        // call respond*() more than once in the same request as a side
        // effect of reusing another controller's handler() directly. PSR-7
        // body streams append on write(), unlike Slim 2's setBody(), which
        // fully replaced the body each time, so the body is rebuilt from
        // scratch here to keep that same "last call wins" behavior.
        $streamFactory = new \Slim\Psr7\Factory\StreamFactory();
        self::$psrResponse = self::$psrResponse
            ->withHeader('Content-Type', 'application/json')
            ->withBody($streamFactory->createStream(json_encode($data)));
    }
}
