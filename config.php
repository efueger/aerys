<?php

use Aerys\{ Host, Request, Response, Router, function root, function router };

/* --- Global server options -------------------------------------------------------------------- */

const AERYS_OPTIONS = [
    "keepAliveTimeout" => 60,
    //"deflateMinimumLength" => 0,
];

/* --- http://localhost:1337/ ------------------------------------------------------------------- */

$router = router()
    ->get("/", function(Request $req, Response $res) {
        $res->send("<html><body><h1>Hello, world.</h1></body></html>");
        /*
        $res->stream("<html><body><h1>Hello, world.</h1>");
        $res->flush();
        $res->stream("</body></html>");
        $res->end();
        */
    })
    ->get("/router/{myarg}", function(Request $req, Response $res, array $routeArgs) {
        $body = "<html><body><h1>Route Args at param 3</h1>".print_r($routeArgs, true)."</body></html>";
        $res->send($body);
    })
    ->post("/", function(Request $req, Response $res) {
        $res->send("<html><body><h1>Hello, world (POST).</h1></body></html>");
    })
    ->get("error1", function(Request $req, Response $res) {
        // ^ the router normalizes the leading forward slash in your URIs
        $nonexistent->methodCall();
    })
    ->get("/error2", function(Request $req, Response $res) {
        throw new Exception("wooooooooo!");
    })
    ->get("/directory/?", function(Request $req, Response $res) {
        // The trailing "/?" in the URI allows this route to match /directory OR /directory/
        $res->send("<html><body><h1>Dual directory match</h1></body></html>");
    })
    ->get("/long-poll", function(Request $req, Response $res) {
        while (true) {
            $res->stream("hello!<br/>")->flush();
            yield new Amp\Pause(1000);
        }
    })
    ->post("/body1", function(Request $req, Response $res) {
        $body = yield $req->getBody();
        $res->send("<html><body><h1>Buffer Body Echo:</h1><pre>{$body}</pre></body></html>");
    })
    ->post("/body2", function(Request $req, Response $res) {
        $body = "";
        foreach ($req->getBody()->stream() as $bodyPart) {
            $body .= yield $bodyPart;
        }
        $res->send("<html><body><h1>Stream Body Echo:</h1><pre>{$body}</pre></body></html>");
    })
    ->get("/favicon.ico", function(Request $req, Response $res) {
        $res->setStatus(404);
        $res->setHeader("Aerys-Generic-Response", "enable");
        $res->end();
    })
    ->zanzibar("/zanzibar", function (Request $req, Response $res) {
        $res->end("<html><body><h1>ZANZIBAR!</h1></body></html>");
    })
;

// If none of our routes match try to serve a static file
$root = root($docrootPath = __DIR__);

// If no static files match fallback to this
$fallback = function(Request $req, Response $res) {
    $res->send("<html><body><h1>Fallback \o/</h1></body></html>");
};

(new Host)->use($router)->use($root)->use($fallback);
