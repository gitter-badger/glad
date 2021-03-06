<?php

namespace Glad;

use Glad\Driver\Repository\RepositoryInterface;
use Glad\Interfaces\ConditionsInterface;
use Glad\Interfaces\CryptInterface;
use Glad\Interfaces\HashInterface;
use Glad\Services\DatabaseService;
use Glad\Event\Dispatcher;
use Glad\GladProvider;
use Glad\Constants;
use Glad\Injector;
use Closure;
use ReflectionClass;
use ErrorException;

/**
 * Auth process class
 *
 * @author Ahmet ATAY
 * @category Authentication
 * @package Glad
 * @copyright 2015
 * @license http://opensource.org/licenses/MIT MIT license
 * @link https://github.com/atayahmet/glad
 */
class Author
{
	/**
     * Repository instance
     *
     * @var object
     */
	protected static $repository;
	
	/**
     * Author service class name
     *
     * @var string
     */
	protected static $author;

	/**
     * Constant class instance
     *
     * @var object
     */
	protected static $constants;

	/**
     * Implemented model object
     *
     * @var object
      */
	protected static $model;

	/**
     * Query builder object
     *
     * @var object
     */
	protected static $queryBuilder;

	/**
     * Identity fields
     *
     * @var array
     */
	protected static $fieldIdentity;

	/**
     * Injector class
     *
     * @var object
     */
	protected static $injector;

	/**
     * Temp user data
     *
     * @var array
     */
	protected static $tempUser;

	/**
     * Safe user data
     *
     * @var array
     */
	protected static $user;

	/**
     * User session data
     *
     * @var array
     */
	protected static $userData;

	/**
     * Register transaction result
     *
     * @var object
     */
	protected static $registerResult;

	/**
     * Change transaction result
     *
     * @var bool
     */
	protected static $changeResult;

	/**
     * Remember me
     *
     * @var bool
     */
	protected static $rememberMe = false;

	/**
     * All the results of operations
     *
     * @var bool
     */
	protected static $processResult = false;

	/**
     * status of operations
     *
     * @var bool
     */
	protected static $status = false;

	/**
     * Instance of EventDispatcher class
     *
     * @var object
     */
	protected static $eventDispatcher;

	/**
     * Instance of Reflection class
     *
     * @var object
     */
	protected static $reflection;
	protected static $crypt;

	/**
     * Class constructor
     *
     * @param object $repository
     * @param object $author
     *
     * @return void
     */ 
	public function __construct(Constants $constants, Injector $injector, CryptInterface $crypt, DatabaseService $databaseService, RepositoryInterface $repository, Dispatcher $eventDispatcher)
	{
		static::$constants = $constants;
		static::$injector = $injector;
		static::$repository = $repository;
		static::$author = GladProvider::$author;
		static::$eventDispatcher = $eventDispatcher;
		static::$crypt = $crypt;
		static::$eventDispatcher->setInstance(static::getInstance());
		static::$userData = static::$repository->get('_gladAuth');

		static::$model = $databaseService->get(static::$injector->get('db'));
	}

	/**
     * New account handler method
     *
     * @param array $credentials
     * @return self instance
     */ 
	public static function register(HashInterface $hash, array $credentials)
	{
		static::resetCheckVariables();

		if(static::guest() === true){

			static::checkIdentityAsParameter($credentials);
			
			if(! static::checkIdentityForRealUser($credentials)) {
				static::$registerResult = false;
			}else{
				static::$tempUser = $credentials;

				$credentials['password'] = $hash->make($credentials['password']);

				static::$registerResult = static::$model->insert($credentials);
				static::$user = $credentials;

				if(static::$registerResult){
					static::$processResult = true;
				}
			}
		}

		return static::getInstance();
	}

	/**
     * Change the user information
     *
     * @param object $credentials
     *
     * @return self instance
     */ 
	public static function change(HashInterface $hash, array $credentials)
	{
		static::resetCheckVariables();

		if(static::check() === true) {

			$credentials = static::cryptPasswordIfFieldExists($hash, $credentials);

			$tableIncrementField = static::$constants->id;
			
			$where = ['and' => [$tableIncrementField => static::getUserId()]];
			static::$changeResult = static::$model->update($where,$credentials);

			if(static::$changeResult){
				static::$user = static::$model->getIdentityWithId(static::getUserId());
				static::$processResult = true;
			}
		}
		return static::getInstance();
	}

	/**
     * Crypt password if password field 
     *
     * @param object $credentials
     *
     * @return array
     */ 
	protected static function cryptPasswordIfFieldExists(HashInterface $hash, array $credentials)
	{
		$fields = static::$constants->authFields;

		if(isset($credentials[$fields['password']])) {
			$credentials['password'] = $hash->make($credentials['password']);
		}
		return $credentials;
	}

	/**
     * User login process
     *
     * @param object $bcrypt
     * @param array $user
     * @param bool $remember
     *
     * @return self instance
     */ 
	public static function login(HashInterface $hash, array $user, $remember = false)
	{
		static::resetCheckVariables();

		$passField = static::$constants->authFields['password'];

		if(!isset($user[$passField]) || static::check() === true){
			return static::getInstance();
		}

		$result = static::$model->getIdentity(static::getIdField($user));
		static::$user = static::resolveDbResult($result);
		
		if(count(static::$user) < 1) return static::getInstance();

		if(!isset(static::$user[$passField])){
			return static::getInstance();
		}
		
		$login = $hash->verify($user[$passField], static::$user[$passField]);

		if($login === true) {
			if($remember === true) {
				static::setRemember(static::$user);
			}

			static::$rememberMe = $remember;
			static::$processResult = true;
		}
		return static::getInstance();
	}

	protected static function setRemember(array $userData)
	{
		$rememberConf = static::$constants->remember;

		if($rememberConf['enabled'] === true) {
			
			if(! isset($userData[$rememberConf['field']])) {
				throw new ErrorException($field . " fields is missing on database");
			}

			$cookieName = $rememberConf['cookieName'];
			$lifeTime = time()+$rememberConf['lifetime'];
			$token = static::$crypt->encrypt(time()+$rememberConf['lifetime']);
			$tokenDecrypted = static::$crypt->decrypt($token);
			$userData[$rememberConf['field']] = $token;
			$cryptedValue = static::$crypt->encrypt(json_encode($userData));
			$setResult = setcookie($cookieName, $cryptedValue, $lifeTime, "/", ".".$_SERVER['HTTP_HOST'], false, true);
			
			if($setResult) {
				$where = ['and' => [static::$constants->id => $userData[static::$constants->id]]];
				$result = static::$model->update($where,[$rememberConf['field'] => $token]);	
			}
		}
	}

	protected static function loginFromRemember()
	{
		$rememberConf = static::$constants->remember;

		if($rememberConf['enabled'] === true) {
			$cookieName = $rememberConf['cookieName'];

			if(isset($_COOKIE[$cookieName])) {
 				
 				$userData = static::$crypt->decrypt($_COOKIE[$cookieName]);
 				$userDataArr = json_decode($userData , true);

 				if(! json_last_error() && isset($userDataArr[$rememberConf['field']])) {
 					
 					$token = $userDataArr[$rememberConf['field']];
 					$tokenDecrypted = static::$crypt->decrypt($token);

 					if(intval($tokenDecrypted) > time()) {

 						static::$user = static::resolveDbResult($userDataArr);
 						$result = static::setUserRepository(static::$user);

 						return $result;
 					}
 					
 				}
			}
		}

		return false;
	}
	/**
     * Applies some conditions after transaction
     *
     * @param instance Closure
     *
     * @return bool
     */ 
	public static function apply(Closure $apply)
	{
		// $processResult: result of all processes variable
		if(static::$processResult === true){
			$apply(static::getInstance());

			if(static::getInstance()->conditionsRun()){
				return true;
			}
			return static::$processResult = false;
		}
	}

	/**
     * Registering event at some methods
     *
     * @param string $name
     * @param instance Closure
     *
     * @return self instance
     */ 
	public static function event($name, Closure $event)
	{
		static::$eventDispatcher->set($name, $event);
		return static::getInstance();
	}

	/**
     * Registering validate condition for some transactions
     *
     * @param string $conditions
     * @param instance Glad\Interfaces\ConditionsInterface
     *
     * @return self instance
     */ 
	public function conditions(array $conditions, ConditionsInterface $cond)
	{
		$cond->add($conditions);
		return static::getInstance();
	}

	/**
     * Run the conditions
     *
     * @param instance Glad\Interfaces\ConditionsInterface
     *
     * @return bool
     */ 
	protected function conditionsRun(ConditionsInterface $conditions)
	{
		if(static::$user && static::$processResult == true && $conditions->apply(static::$user, [], static::$eventDispatcher)){
			return static::status();
		}

		return false;
	}

	/**
     * User login process by user id
     *
     * @param int $userId
     * @return bool
     */ 
	public static function loginByUserId($userId = false, $remember = false)
	{
		static::resetCheckVariables();

		if(static::check() === true || ! $userId) return static::getInstance();
		$result = static::$model->getIdentityWithId($userId);

		if(count($result) > 0) {
			
			static::$user = static::resolveDbResult($result);
			
			if(isset(static::$user) && is_array(static::$user)){
				static::$rememberMe = $remember;
				static::$processResult = true;
				static::status();
			}
		}
		return static::getInstance();
	}

	/**
     * User logout
     *
     * @return bool
     */ 
	public static function logout()
	{
		$result = static::$repository->delete('_gladAuth');
		
		static::$userData = static::userData();

		return $result;
	}

	/**
     * Reset the check variables
     *
     * @return void
     */ 
	protected static function resetCheckVariables()
	{
		static::$processResult = false;
		static::$status = false;
	}

	/**
     * Sets user data to repository
     *
     * @param $user array
     * @return bool
     */ 
	protected static function setUserRepository(array $user)
	{
		$userData = [
			'userData' => $user,
			'auth' => ['status' => true]
		];

		return static::$repository->set('_gladAuth', serialize($userData));
	}

	/**
     * Gets user data from repository
     *
     * @return array
     */ 
	public static function userData()
	{
		$data = static::$repository->get('_gladAuth');

		if($data && is_array(unserialize($data))) {
			$passField = static::$constants->authFields['password'];
			$unSerialize = unserialize($data);

			unset($unSerialize['userData'][$passField]);
			
			return $unSerialize['userData'];
		}
	}

	/**
     * Gets container class instane
     *
     * @return object
     */ 
	protected static function getInstance()
	{
		return static::$injector->inject('Glad\Glad');
	}

	/**
     * Login to after register transaction
     *
     * @return void|bool
     */ 
	public static function andLogin()
	{
		if(static::status()){
			static::getInstance()->login(static::$tempUser);
			static::$tempUser = [];
		}
		return false;
	}

	/**
     * Gets process status and set user data
     *
     * @return bool
     */ 
	public static function status()
	{
		if(static::$processResult === true){
			static::setUserRepository(static::$user, static::$rememberMe);
			static::$userData = static::$repository->get('_gladAuth');
			static::$processResult = false;
			static::$status = true;
		}
		return static::$status;
	}

	/**
     * Controls the given parameter
     *
     * @param array $credentials
     * @return bool|exception
     */ 
	protected static function checkIdentityAsParameter($credentials)
	{
		try {
			$fields = static::$constants->authFields;
			
			foreach($fields['identity'] as $field){
				if(! isset($credentials[$field])){
					throw new \Exception("Identity fields is missing");
				}
			}

			if( !$credentials[$fields['password']]) {
				throw new \Exception("Password field required!", 1);
			}

			return true;
		}
		catch(Exception $e){
			throw $e;
		}
	}

	/**
     * Return the user id
     *
     * @return int|null
     */ 
	public static function getUserId()
	{
		$userData = static::getData();

		if($userData) {
			$tableIncrementField = static::$constants->id;
			$userId = $userData[$tableIncrementField];

			return is_numeric($userId) ? (int)$userId : $userId;
		}
	}

	/**
     * Return user data if user logged in
     *
     * @return array|null
     */ 
	protected static function getData()
	{
		if(static::authStatus()) {
			return static::userData();
		}
	}

	/**
     * Check the user in database by parameters
     * Arranges the data coming from database
     *
     * @param array $credentials
     * @return bool
     */ 
	protected static function checkIdentityForRealUser(array $credentials)
	{
		$result = static::$model->getIdentity(static::getIdField($credentials));
		$result = static::resolveDbResult($result);
		
		return count($result) < 1;
	}

	/**
     * Check and gets authenticate fields
     *
     * @param array $credentials
     * @return array
     */ 
	protected static function getIdField(array $credentials)
	{
		$identity = static::$constants->authFields['identity'];
		$fields = [];

		if(is_array($identity)){
			foreach($identity as $id){
				if(isset($credentials[$id])){
					$fields[$id] = static::xssClean($credentials[$id]);
				}
			}
		}

		return $fields;
	}

	/**
     * Controls the given data from implemented model
     *
     * @param array $result
     * @return array|exception
     */ 
	protected static function resolveDbResult($result)
	{
		$exception = false;

		if(!isset($result) || !$result) return [];
		if(! is_array(reset($result))) return $result;

		foreach($result as $key => $value){
			if(is_numeric($key) && (!is_array($result[$key]) && !is_object($result[$key])) ){
				$exception = true;
			}
		}

		if(! $exception){
			return (array)reset($result);
		}

		throw new \Exception('return data incorrect');
	}

	/**
     * Clean xss data
     *
     * @param string $input
     * @return string
     */ 
	protected static function xssClean($input)
	{
		$input = strip_tags($input);
		$input = filter_var($input, FILTER_SANITIZE_STRING);

		return $input;
	}

	/**
     * Gets user logged in status
     *
     * @return bool
     */ 
	public static function check()
	{
		return static::authStatus();
	}

	/**
     * Gets user log out status
     *
     * @return bool
     */ 
	public static function guest()
	{
		return !static::authStatus();
	}

	/**
     * Runs some methods
     *
     * @param string $method
     * @return bool|null
     */ 
	public static function is($method)
	{
		if(static::_hasMethod($method)) {
			return static::$method();
		}
	}

	/**
     * Detects the presence of the methods by ReflectionClass
     *
     * @param string $method
     * @return bool
     */ 
	protected static function _hasMethod($method)
	{
		if(is_null(static::$reflection)) {
			static::$reflection = new ReflectionClass("Glad\Author");
		}

		return static::$reflection->hasMethod($method);
	}

	/**
     * Return user logged in status to other methods
     *
     * @return bool
     */ 
	protected static function authStatus()
	{
		static::getInstance()->conditionsRun();

		$auth = static::$userData;

		if(! is_array($auth)) $auth = unserialize($auth);

		if($auth && isset($auth['auth'])) {
			return isset($auth['auth']['status']) && $auth['auth']['status'] === true;
		}else{
			return static::loginFromRemember();
		}

		return false;
	}
}