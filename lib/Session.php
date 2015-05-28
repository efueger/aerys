<?php

namespace Aerys;

use Amp\{
    Promise,
    function pipe,
};

class Session implements \ArrayAccess {
    const CONFIG = [
        "name" => "AerysSessionId",
        "ttl" => -1,
    ];

    private $response;
    private $id;
    private $config;
    private $data;
    private $writable = false;
    private $readPipe;
    private $defaultPipe;

    /**
     * @param Request $request
     * @param Response $response
     */
    public function  __construct(Request $request, Response $response) {
        $this->readPipe = function(array $data) {
            $this->data = $data;
            return $this;
        };
        $this->defaultPipe = function() {
            return $this;
        };

        $this->config = $request->getLocalVar("aerys.session");

        if (!isset($this->config["cookie_flags"])) {
            $info = $request->getConnectionInfo();
            $this->config["cookie_flags"] = $info["is_encrypted"] ? ["secure"] : [];
        }

        $this->config += static::CONFIG;

        $this->id = $request->getCookie($this->config["name"]) ?? $this->generateId();
        $this->setSessionCookie();
    }


    private function generateId() {
        return bin2hex(random_bytes(24));
    }

    private function setSessionCookie() {
        $expires = $this->config["ttl"] == -1 ? [] : ["Expires" => date(\DateTime::RFC1123, time() + $this->config["ttl"])];
        $this->response->setCookie($this->config["name"], $this->id, $expires + $this->config["cookie_flags"]);
    }

    public function setTTL(int $ttl) {
        $this->config["ttl"] = $ttl;
        $this->setSessionCookie();
    }

    public function offsetExists($offset) {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet($offset) {
        if (!array_key_exists($offset, $this->data)) {
            throw new \Exception("Key '$offset' does not exist in session"); // @TODO change to more specific exception
        }

        return $this->data[$offset];
    }

    public function offsetSet($offset, $value) {
        if (!$this->writable) {
            throw new \Exception("Session is not locked, can't write"); // @TODO change to more specific exception
        }
        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset) {
        unset($this->data[$offset]);
    }

    /**
     * Creates a lock and reads the current session data
     * @return \Amp\Promise resolving after success
     */
    public function open(): Promise {
        $this->writable = true;
        return pipe($this->config["driver"]->open($this->id), $this->readPipe);
    }

    /**
     * Saves and unlocks a session
     * @return \Amp\Promise resolving after success
     */
    public function save(): Promise {
        $this->writable = false;
        return pipe($this->config["driver"]->save($this->id, $this->data), $this->defaultPipe);
    }

    /**
     * Reloads the session contents and locks
     * @return \Amp\Promise resolving after success
     */
    public function read(): Promise {
        return pipe($this->config["driver"]->read($this->id), $this->readPipe);
    }

    /**
     * Unlocks the session, reloads data without saving
     * @return \Amp\Promise resolving after success
     */
    public function unlock(): Promise {
        $this->writable = false
        return pipe($this->config["driver"]->unlock(), function() {
            return pipe($this->config["driver"]->read($this->id), $this->readPipe);
        });
    }

    /**
     * Regenerates a session id
     * @return \Amp\Promise resolving after success
     */
    public function regenerate(): Promise {
        $new = $this->generateId();
        $promise = $this->config["driver"]->regenerate($this->id, $new);
        $this->id = $new;
        $this->setSessionCookie();
        return pipe($promise, $this->defaultPipe);
    }

    /**
     * Destroys the session
     * @return \Amp\Promise resolving after success
     */
    public function destroy(): Promise {
        $promise = $this->config["driver"]->destroy($this->id);
        $this->id = $this->generateId();
        $this->setSessionCookie();
        $this->data = [];
        return pipe($promise, $this->defaultPipe);
    }

    public function __destruct() {
        $this->save();
    }
}