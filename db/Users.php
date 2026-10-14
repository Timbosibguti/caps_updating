<?php

use DB\Repository\UserRepository;

class Users {
    private $userRepository;

    public function __construct() {
        $this->userRepository = new UserRepository();
    }

    public function getUserByEmail($email) {
        return $this->userRepository->findByEmail($email);
    }

    public function addUserByEmail($email) {
        return $this->userRepository->addUserByEmail($email);
    }
} 