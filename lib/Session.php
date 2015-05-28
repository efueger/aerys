<?php

namespace Aerys;

use Amp\Promise;

class Session {
    const CONFIG = [
        "name" => "AerysSessionId",
        "ttl" => -1,
    ];

    private $response;
    private $id;
    private $config;

    /**
     * @param Request $request
     * @param Response $response
     */
    public function  __construct(Request $request, Response $response) {
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

    public function regenerate() {
        $this->id = $this->generateId();
        $this->setSessionCookie();
    }

    private function setSessionCookie() {
        $expires = $this->config["ttl"] == -1 ? [] : ["Expires" => date(\DateTime::RFC1123, time() + $this->config["ttl"])];
        $this->response->setCookie($this->config["name"], $this->id, $expires + $this->config["cookie_flags"]);
    }

    public function setTTL(int $ttl) {
        $this->config["ttl"] = $ttl;
        $this->setSessionCookie();
    }

    /**
     * @return \Amp\Promise resolving to a \stdClass with current session data
     */
    public function open(): Promise {
        return $this->config["driver"]->open($this->id);
    }

    /**
     * @param \stdClass $data to store
     * @return \Amp\Promise resolving after success
     */
    public function save(\stdClass $data): Promise {
        return $this->config["driver"]->save($this->id);
    }

    /**
     * Create a lock so that nobody can read nor write the session data form now on
     * @return \Amp\Promise resolving when a lock could be created
     */
    public function lock(): Promise {
        return $this->config["driver"]->lock($this->id);
    }

    /**
     * Unlocks the session so that session can be read and written to again
     * @return \Amp\Promise resolving after success
     */
    public function unlock(): Promise {
        return $this->config["driver"]->unlock($this->id);
    }

    /**
     * Destroys the session
     * @return \Amp\Promise resolving after success
     */
    public function destroy(): Promise {
        $this->config["driver"]->destroy($this->id);
        $this->regenerate();
    }

    public function __destruct() {
        $this->unlock();
    }
}