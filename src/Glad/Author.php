<?php

namespace Glad;

use Glad\Driver\Repository\RepositoryInterface;
use Glad\AuthorInterface;
use Closure;

class Author implements AuthorInterface {

	/**
    * Repository instance
    *
    * @var object
    */
	protected static $repository;
	
	/**
    * Author instance
    *
    * @var object
    */
	protected static $author;

	/**
    * Class constructor
    *
    * @param object $repository
    * @param object $author
    *
    * @return void
    */ 
	public function __construct(RepositoryInterface $repository)
	{
		static::$repository = $repository;

		//static::$author = $author;
	}

	public function getIdentity(RepositoryInterface $session, RepositoryInterface $auth)
	{
		$session->get('hello');
	}

	public function getRepository(RepositoryInterface $repository)
	{
		return $repository;
	}

	public static function register(array $credentials, OrmInterface $orm)
	{
		exit(var_dump($orm));
	}

	public function login(array $user, $remember, RepositoryInterface $repository)
	{
		return $repository;
	}

	public function check()
	{
		
	}

	public function guest()
	{

	}

	public function is($type)
	{

	}

}