<?php
/**
 * The Fortissimo core.
 *
 * This file contains the core classes necessary to bootstrap and run an
 * application that makes use of the Fortissimo framework.
 */

/**
 * This constant contains the request start time.
 *
 * For optimal performance, use this instead of {@link time()}
 * to generate the NOW time.
 */
define('FORTISSIMO_REQ_TIME', time());

// Set the include path to include Fortissimo directories.
$basePath = dirname(__FILE__); 
$paths[] = get_include_path();
$paths[] = $basePath . '/includes';
$paths[] = $basePath . '/core';
$path = implode(PATH_SEPARATOR, $paths);
set_include_path($path);

/**
 * QueryPath is a core Fortissimo utility.
 * @see http://querypath.org
 */ 
require_once('QueryPath/QueryPath.php');
 
/**
 * The Fortissimo front controller.
 *
 * This class is used to bootstrap Fortissimo and oversee execution of a
 * Fortissimo request. Unlike Rhizome, there is no split between the 
 * front controller and the request handler. The front controller assumes that
 * the application will be run either as a CLI or as a web application. And it
 * is left to commands to execute behaviors specific to their execution 
 * environment.
 *
 * Typically, the entry point for this class is {@link handleRequest()}, which 
 * takes a request name and executes all associated commands.
 *
 * For more details, see {@link __construct()}.
 */
class Fortissimo {
  
  protected $commandConfig = NULL;
  protected $initialConfig = NULL;
  protected $logManager = NULL;
  protected $cxt = NULL;
  protected $cacheManager = NULL;
  
  /**
   * Construct a new Fortissimo server.
   *
   * The server is lightweight, and optimized for PHP's single request model. For
   * advanced cases, one server can handle multiple requests, and performance will
   * scale linearly (dependent, of course, on the commands themselves). However,
   * since the typical PHP application handles only one request per invocation, 
   * this controller will attempt to bootstrap very quickly with minimal loading.
   *
   * It should be illegal to eat bananas on a crowded train. They smell bad, and 
   * people chomp on them, which makes a gross noise.
   *
   * @param mixed $commandsXMLFile
   *  A configuration pointer. Typically, this is a filename of the commands.xml
   *  file on the filesystem. However, straight XML, a DOMNode or DOMDocument, 
   *  and a SimpleXML object are among the various objects that can be passed
   *  in as $commandsXMLFile.
   * @param array $configData
   *  Any additional configuration data can be added here. This information 
   *  will be placed into the {@link FortissimoExecutionContext} that is passsed
   *  into each command. In this way, information passed here should be available
   *  to every command, as well as to the overarching framework.
   */
  public function __construct($commandsXMLFile, $configData = array()) {
    
    $this->initialConfig = $configData;
    
    // Parse configuration file.
    $this->commandConfig = new FortissimoConfig($commandsXMLFile);
    
    // Create the log manager.
    $this->logManager = new FortissimoLoggerManager($this->commandConfig->getLoggers());
    
    // Create cache manager.
    $this->cacheManager = new FortissimoCacheManager($this->commandConfig->getCaches());
  }
  
  public function genCacheKey($requestName) {
    return 'request-' . $requestName;
  }
  
  /**
   * Handles a request.
   *
   * When a request comes in, this method is responsible for displatching
   * the request to the necessary commands, executing commands in sequence.
   */
  public function handleRequest($requestName, FortissimoExecutionContext $initialCxt = NULL) {
    $request = $this->commandConfig->getRequest($requestName);
    $cacheKey = NULL; // This is set only if necessary.
    
    // If this allows caching, check the cached output.
    if ($request->isCaching() && isset($this->cacheManager)) {
      // Handle caching.
      $cacheKey = $this->genCacheKey($requestName);
      $response = $this->cacheManager->get($cacheKey);
      
      // If a cached version is found, print that data and return.
      if (isset($response)) {
        print $response;
        return;
      }
      
      // Turn on output buffering. We use this to capture data
      // for insertion into the cache.
      ob_start();
    }
    
    // This allows pre-seeding of the context.
    if (isset($initialCxt)) {
      $this->cxt = $initialCxt;
    }
    // This sets up the default context.
    else {
      $this->cxt = new FortissimoExecutionContext($this->initialConfig, $this->logManager);
    }
    
    foreach ($request as $command) {
      try {
        $this->execCommand($command);
      }
      // Kill the request and log an error.
      catch (FortissimoInterruptException $ie) {
        $this->logManager->log($e);
        return;
      }
      // Forward any requests.
      catch (FortissimoForwardRequest $forward) {
        $this->handleRequest($forward->destination(), $forward->context());
        return;
      }
      // Kill the request, no error.
      catch (FortissimoInterrupt $i) {
        return;
      }
      // Log the error, but continue to the next command.
      catch (FortissimoException $e) {
        $this->logManager->log($e);
        continue;
      }
    }
    
    // If caching is on, place this entry into the cache.
    if ($request->isCaching() && isset($this->cacheManager)) {
      $contents = ob_get_contents();
      
      // Add entry to cache.
      $this->cacheManager->set($cacheKey, $contents);
      // Turn off output buffering & send to client.
      ob_end_flush();
    }    
  }
  
  /**
   * Retrieve the associated logger manager.
   *
   * @return FortissimoLoggerManager
   *  The logger manager overseeing logs for this server.
   * @see FortissimoLogger
   * @see FortissimoLoggerManager
   * @see FortissimoOutputInjectionLogger
   */
  public function loggerManager() {
    return $this->logManager;
  }
  
  /**
   * Get the caching manager for this server.
   *
   * @return FortissimoCacheManager
   *  The cache manager for this server.
   */
  public function cacheManager() {
    return $this->cacheManager;
  }
  
  /**
   * Execute a single command.
   *
   * @param array $commandArray
   *  An associative array, as described in {@link FortissimoConfig::createCommandInstance}.
   * @param FortissimoExecutionContext $cxt
   *  The context of this request. This is passed from command to command.
   * @throws FortissimoException
   *  Thrown if the command failed, but execution should continue.
   * @throws FortissimoInterrupt
   *  Thrown if the command wants to interrupt the normal flow of execution and
   *  immediately return to the client.
   */
  protected function execCommand($commandArray) {
    // We should already have a command object in the array.
    $inst = $commandArray['instance'];
    
    $params = $this->fetchParameters($commandArray, $this->cxt);
    
    try {
      $inst->execute($params, $this->cxt);
    }
    // Only catch a FortissimoException. Allow FortissimoInterupt to go on.
    catch (FortissimoException $e) {
      $this->logManager->log($e);
    }
  }
  
  /**
   * Retrieve the parameters for a command.
   *
   * @param array $commandArray
   *  Associative array of information about a command, as described
   *  in {@link FortissimoConfig::createCommandInstance}.
   * @param FortissimoExecutionContext $cxt
   *  The execution context.
   */
  protected function fetchParameters($commandArray) {
    $params = array();
    foreach ($commandArray['params'] as $name => $config) {
      
      
      // If there is a FROM source, fetch the data from the designated source(s).
      if (!empty($config['from'])) {
        // Handle cases like this: 'from="get:preferMe post:onlyIfNotInGet"'
        $fromItems = explode(' ', $config['from']);
        $value = NULL;
        
        // Stop as soon as a parameter is fetched and is not NULL.
        foreach ($fromItems as $from) {
          $value = $this->fetchParameterFromSource($from);
          if (isset($value)) {
            $params[$name] = $value;
            break;
          }
        }
      }
            
      // Set the default value if necessary.
      if (!isset($params[$name])) $params[$name] = $config['value'];
    }
    return $params;
  }
  
  /**
   * Parse a parameter specification and retrieve the appropriate data.
   *
   * @param string $from
   *  A parameter specification of the form <source>:<name>. Examples:
   *  - get:myParam
   *  - post:username
   *  - cookie:session_id
   *  - session:last_page
   *  - cmd:lastCmd
   *  - env:cwd
   *  Known sources:
   *  - get
   *  - post
   *  - cookie
   *  - session
   *  - cmd (retrieved from the execution context.)
   *  - env
   *  - server
   *  - request
   *  - argv (From $argv, assumes that the format of from is argv:N, where N is an integer)
   * @param FortissimoExecutionContext $cxt
   *  The current working context. This is used to retrieve data from cmd: 
   *  sources.
   * @return string 
   *  The value or NULL.
   */
  protected function fetchParameterFromSource($from) {
    list($proto, $paramName) = explode(':', $from, 2);
    $proto = strtolower($source);
    switch ($proto) {
      case 'g':
      case 'get':
        return $_GET[$paramName];
      case 'p':
      case 'post':
        return $_POST[$paramName];
      case 'c':
      case 'cookie':
      case 'cookies':
        return $_COOKIE[$paramName];
      case 's':
      case 'session':
        return $_SESSION[$paramName];
      case 'x':
      case 'cmd':
      case 'cxt':
      case 'context':
        return $this->cxt[$paramName];
      case 'e':
      case 'env':
      case 'environment':
        return $_ENV[$paramName];
      case 'server':
        return $_SERVER[$paramName];
      case 'r':
      case 'request':
        return $_REQUEST[$paramName];
      case 'a':
      case 'arg':
      case 'argv':
        return $argv[(int)$paramName];
    }
  }
}


/**
 * A Fortissimo request.
 *
 * This class represents a single request.
 */
class FortissimoRequest implements IteratorAggregate {
  
  protected $commandQueue = NULL;
  protected $isCaching = FALSE;
  
  public function __construct($commands) {
    $this->commandQueue = $commands;
  }
  
  /**
   * Get the array of commands.
   *
   * @return array
   *  An array of commands.
   */
  public function getCommands() {
    return $this->commandQueue;
  }
  
  /**
   * Set the flag indicating whether or not this is caching.
   */
  public function setCaching($boolean) {
    $this->isCaching = $boolean;
  }
  
  /**
   * Determine whether this request can be served from cache.
   *
   * Request output can sometimes be cached. This flag indicates
   * whether the given request can be served from a cache instead
   * of requiring the entire request to be executed.
   *
   * @return boolean
   *  Returns TRUE if this can be served from cache, or 
   *  FALSE if this should not be served from cache.
   * @see FortissimoRequestCache
   */
  public function isCaching() {
    return $this->isCaching;
  }
  
  /**
   * Get an iterator of this object.
   *
   * @return Iterator
   */
  public function getIterator() {
    return new ArrayIterator($this->commandQueue);
  }
}

/**
 * A Fortissimo command.
 *
 * The main work unit in Fortissimo is the FortissimoCommand. A FortissimoCommand is
 * expected to conduct a single unit of work -- retrieving a datum, running a 
 * calculation, doing a database lookup, etc. Data from a command (if any) can then
 * be stored in the {@link FortissimoExecutionContext} that is passed along the 
 * chain of commands.
 *
 * Each command has a request-unique <b>name</b> (only one command in each request
 * can have a given name), a set of zero or more <b>params</b>, passed as an array,
 * and a <b>{@link FortissimoExecutionContext} object</b>. This last object contains
 * the results (if any) of previously executed commands, and is the depository for 
 * any data that the present command needs to pass along.
 *
 * Typically, the last command in a request will format the data found in the context
 * and send it to the client for display.
 */
interface FortissimoCommand {
  /**
   * Create an instance of a command.
   *
   * @param string $name
   *  Every instance of a command has a name. When a command adds information
   *  to the context, it (by convention) stores this information keyed by name.
   *  Other commands (perhaps other instances of the same class) can then interact
   *  with this command by name.
   * @param boolean $caching
   *  If this is set to TRUE, the command is assumed to be a caching command, 
   *  which means (a) its output can be cached, and (b) it can be served
   *  from a cache. It is completely up to the implementation of this interface
   *  to provide (or not to provide) a link to the caching service. See
   *  {@link BaseFortissimoCommand} for an example of a caching service. There is
   *  no requirement that caching be supported by a command.
   */
  public function __construct($name, $caching = FALSE);
  
  /**
   * Execute the command.
   *
   * Typically, when a command is executed, it does the following:
   *  - uses the parameters passed as an array.
   *  - performs one or more operations
   *  - stores zero or more pieces of data in the context, typically keyed by this
   *    object's $name.
   *
   * Commands do not return values. Any data they produce can be placed into 
   * the {@link FortissimoExcecutionContext} object. On the occasion of an error,
   * the command can either throw a {@link FortissimoException} (or any subclass 
   * thereof), in which case the application will attempt to handle the error. Or it 
   * may throw a {@link FortissimoInterrupt}, which will interrupt the flow of the 
   * application, causing the application to forgo running the remaining commands.
   *
   * @param array $paramArray
   *  An associative array of name/value parameters. A value may be of any data 
   *  type, including a classed object or a resource.
   * @param FortissimoExecutionContext $cxt
   *  The execution context. This can be modified by the command. Typically,
   *  though, it is only written to. Reading from the context may have the 
   *  effect of making the command less portable.
   * @throws FortissimoInterrupt
   *  Thrown when the command should be treated as the last command. The entire 
   *  request will be terminated if this is thrown.
   * @throws FortissimoException
   *  Thrown if the command experiences a general execution error. This may not 
   *  result in the termination of the request. Other commands may be processed after
   *  this.
   */
  public function execute($paramArray, FortissimoExecutionContext $cxt);  
  
  /**
   * Indicates whether the command's additions to the context are cacheable.
   *
   * For command-level caching to work, Fortissimo needs to be able to determine
   * what commands can be cached. If this method returns TRUE, Fortissimo assumes
   * that the objects the command places into the context can be cached using
   * PHP's {@link serialize()} function.
   *
   * Just because an item <i>can</i> be cached does not mean that it will. The
   * determination over whether a command's results are cached lies in the
   * the configuration.
   *
   * @return boolean
   *  Boolean TRUE of the object canbe cached, FALSE otherwise.
   */
  public function isCacheable();
}

abstract class BaseFortissimoCommand implements FortissimoCommand {
  /**
   * Indicates parameter is a numeric value.
   * This should only be used when {@link int_type} and {@link float_type}
   * are too specific.
   * @see expects()
   */
  const numeric_type = 0;
  /**
   * Indicates parameter is a string value.
   * @see expects()
   */
  const string_type = 1;
  /**
   * Indicates parameter is a resource value.
   * @see expects()
   */
  const resource_type = 2;
  /**
   * Indicates parameter is an object value.
   *
   * This will check only that the value is an object, not whether it 
   * belongs to a specific class.
   * @see expects()
   */
  const object_type = 3;
  /**
   * Indicates parameter is an array value.
   * @see expects()
   */
  const array_type = 4;
  /**
   * Indicates parameter is an integer value.
   * @see expects()
   */
  const int_type = 5;
  /**
   * Indicates parameter is a float value.
   * @see expects()
   */
  const float_type = 6;
  
  /**
   * The name of this command.
   * Passed from the 'name' value of the command configuration file.
   */
  protected $name = NULL;
  /**
   * The request-wide execution context ({@link FortissimoExecutionContext}).
   *
   * Use this to retrieve the results of other commands. Typically, you will not need
   * to add data to this. Returning data from the {@link doCommand()} method will
   * automatically insert it into the context.
   */
  protected $context = NULL;
  
  /**
   * Flag indicating whether this object is currently (supposed to be)
   * in caching mode.
   *
   * For caching to be enabled, both this flag (which comes from the command
   * config) and the {@link isCacheable()} method must be TRUE.
   */
  protected $caching = FALSE;
  /**
   * An associative array of parameters.
   *
   * These are the parameters passed into the command from the environment. The name
   * will correspond to the 'name' parameter in the command configuration file. The 
   * value is retrieved depending on the 'from' (or default value) rules in the
   * configuration file.
   */
  protected $parameters = NULL;
  
  public function __construct($name, $caching = FALSE) {
    $this->name = $name;
    $this->caching = $caching;
  }
  
  /**
   * By default, a Fortissimo base command is cacheable.
   *
   * @return boolean
   *  Returns TRUE unless a subclass overrides this.
   */
  public function isCacheable() {
    return TRUE;
  }
  
  public function execute($params, FortissimoExecutionContext $cxt) {
    
    $this->parameters = array();
    $expecting = $this->expects();
    foreach ($expecting as $name => $data) {
      if (!isset($params[$name])) {
        throw new FortissimoException(sprintf('Expected param %s in command %s', $name, $this->name));
      }
      
      $filter = $data['filters'];
      $filter_options = $data['filter_options'];
      if (!empty($filter)) {
        $res = filter_var($params[$name], $filter, $filter_options);
      }
      
      // TODO: Switch to the filter library (filter_var)
      /*
      $type = $data['type'];
      $payload = $params[$name];
      if (is_numeric($type)) {
        switch ($type) {
          case self::numeric_type:
            if (!is_numeric($payload))
              throw new FortissimoException(sprintf('Expected number in %s, got non-number.', $this->name));
            break;
          case self::string_type:
            if (!is_string($payload))
              throw new FortissimoException(sprintf('Expected string in %s, got non-string.', $this->name));
            break;
          case self::resource_type: 
            if (!is_resource($payload))
              throw new FortissimoException(sprintf('Expected resource in %s, got non-resource.', $this->name));
            break;
          case self::object_type:
            if (!is_object($payload))
              throw new FortissimoException(sprintf('Expected object in %s, got non-object.', $this->name));
            break;
          case self::array_type:
            if (!is_array($payload))
              throw new FortissimoException(sprintf('Expected array in %s, got non-array.', $this->name));
            break;
          case self::int_type:
            if (!is_numeric($payload))
              throw new FortissimoException(sprintf('Expected integer in %s, got non-number.', $this->name));
            if ((int)$payload != $payload)
              throw new FortissimoException(sprintf('Expected integer in %s, got non-integer number.', $this->name));
            $payload = (int)$payload;
            break;
          case self::float_type:
            if (!is_numeric($payload))
              throw new FortissimoException(sprintf('Expected float in %s, got non-number.', $this->name));
            if ((float)$payload != $payload)
              throw new FortissimoException(sprintf('Expected float in %s, got non-float number.', $this->name));
            $payload = (float) $payload;
            break;
            
          default:
            throw new FortissimoException(sprintf('%d is an unknown type code. Cannot validate data in %s.', $type, $this->name));
        }
      }
      elseif (is_string($type)) {
        $this->verifyClass($payload, $type);
      }
      else {
        throw new FortissimoException(sprintf('Invalid validation type for command %s. Must be an int or a string.', $this->name));
      }
      */
      
      // If a validator is set, execute it.
      // XXX: Do we want to do an instanceof check?
      if (is_object($data['validate'])) {
        $object = $data['validate'];
        $payload = $object->validate($name, $type, $payload);
      }
      $this->parameters[$name] = $payload;
    }
    
    $this->context = $cxt;
    $this->context->add($this->name, $this->doCommand());
  }
  
  protected function verifyClass($obj, $class) {
    $ref = new ReflectionObject($obj);
    return $ref->getName() == $class;
  }
  
  public function explain() {
    $expects = $this->expects();
    $defaults = array('type' => self::string_type, 'description' => '');
    $format = "%s (%s): %s\n\n";
    $buffer = '';
    foreach ($expects as $name => $data) {
      $data += $defaults;
      $buffer .= sprintf($name, $this->typeAsString($data['type']), $data['description']);
    }
  }
  
  protected function typeAsString($type) {
    if (is_numeric($type)) {
      $v = array('number', 'string', 'resource', 'object');
      if ($type < count($v)) return $v[$type];
    }
    return $type;
  }
  
  /**
   * Information about what parameters the command expects.
   *
   * This function returns a registry of variables that the command expects.
   * These variables are derived from the params settings for the command (e.g. from
   * the cmd parameters in the command configuration file).
   *
   * For example, an expects() function may return something like this:
   * <?php
   * array(
   *   'first_name' => array(
   *     'type' => self::string_type,
   *     'description' => 'A first name',
   *   ),
   *   'last_name' => array(
   *     'type' => self::string_type,
   *     'description' => 'A surname',
   *   ),
   *   'age' => array(
   *     'type' => self::numeric_type,
   *     'description' => 'An age, in years (e.g. 28)',
   *     // A callback function will be given the parameter name, type, and value.
   *     'validate' => 'some_valid_callback', // some_valid_callback($name, $type, $value)
   *   ),
   * )
   * ?>
   * The form of this array is $array['name_of_param'] = array(detail => value);
   *
   * Details that MUST be returned in the array:
   * - type: One of the const *_type from this class, or a string classname.
   * - description: The description of what this parameter does.
   *
   * Optional details:
   * - validate: A callback signature which can be used for validation or preprocessing.
   *
   * Using the validate callback:
   * This must be an object that implements {@link FortissimoValidator}.
   * The callback will be called as if executed like this:
   *  $callback->validate($name, $type, $value)
   * Any value it returns will be placed in lieu of $value in the paramters, so this
   * provides a chance to preprocess parameter information. IF THIS PROCESS IS VALIDATING,
   * and the validation fails, it should throw a {@link FortissimoException} or 
   * {@link FortissimoInterruptException} (if it is a fatal error) to stop processing.
   * See {@link FortissimoValidator} for details.
   *
   * This data structure accomplishes several things, including:
   *  - Input validation. Simple validation can be accomplished by telling this object
   *    what kind of data it collects.
   *  - Self-documentation. Calling explain() will return a formatted display of
   *    what arguments this command expects.
   */
  abstract public function expects();
  
  /**
   * Do the command.
   *
   * Performs the work for this command.
   *
   * Every class that extends this base class should implement {@link doCommand()},
   * executing the command's logic, and returning the value or values that should
   * be placed into the execution context.
   *
   * This object provides access to the following variables of interest:
   *  - {@link $name}: The name of the command.
   *  - {@link $parameters}: The name/value list of parameters. These are learned 
   *    and validated based on the contents of the {@link expects()} method.
   *  - {@link $context}: The {@link FortissimoExecutionContext} object for this request.
   * @return mixed
   *  A value to be placed into the execution environment. The value can be retrieved
   *  using <code>$cxt->get($name)</code>, where <code>$name</code> is the value of this
   *  object's $name variable.
   * @throws FortissimoException
   *  Thrown when an error occurs, but the application should continue.
   * @throws FortissimoInterrupt
   *  Thrown when this command should terminate the request. This is a NON-ERROR condition.
   * @throws FortissimoInterruptException
   *  Thrown when a fatal error occurs and the request should terminate.
   */
  abstract public function doCommand();
}

/**
 * Validate or transform arguments passed to a {@link BaseFortissimoCommand}.
 *
 * Fortissimo validators are called from within the BaseFortissimoCommand. They
 * provide extended validation of a parameter before the parameter is used to 
 * execute the command.
 */
interface FortissimoValidator {
  /**
   * Validate a single param.
   *
   * @param string $name
   *  The name of the parameter.
   * @param mixed $type
   *  The type (reported by {@link BaseFortissimoCommand::explain()}) of teh 
   *  parameter.
   * @param mixed $value
   *  The value of the parameter.
   * @return mixed
   *  The return value will be used in lieu of the original $value.
   * @throws FortissimoException
   *  If validation fails, a FortissimoException should be thrown.
   * @throws FortissimoInterrupt
   *  If the process of validating indicates thtat the request should be 
   *  terminated, this should be thrown.
   * @throws FortissimoInterruptException
   *  If validation fails and the request should be terminated, this should be thrown.
   */
  public function validate($name, $type, $value);
}

/**
 * Stores information about Fortissimo commands.
 *
 * This is used when bootstrapping to map a request to a series of commands.
 * Note that this does not provide an object represenation of the configuration
 * file. Instead, it interprets the configuration file, and assembles the 
 * information as the application needs it. To get directly at the configuration
 * information, use {@link getConfig()}.
 */
class FortissimoConfig {
  
  protected $config;
  
  /**
   * Construct a new configuration object.
   *
   * @param mixed $commandsXMLFile
   *  A pointer to configuration information. Typically, this is a filename. 
   *  However, it may be any object that {@link qp()} can process.
   *
   * @see http://api.querypath.org/docs
   */
  public function __construct($commandsXMLFile) {
    $this->config = qp($commandsXMLFile);
  }
  
  /**
   * Check whether the named request is known to the system.
   *
   * @return boolean
   *  TRUE if this is a known request, false otherwise.
   */
  public function hasRequest($requestName){
    if (!self::isLegalRequestName($requestName))  {
      throw new FortissimoException('Illegal request name.');
    }
    return $this->config->top()->find('request[name="' . $requestName . '"]')->size() > 0;
  }
  
  /**
   * Validate the request name.
   *
   * A request name can be any combination of one or more alphanumeric characters,
   * together with dashes (-) and underscores (_).
   *
   * @param string $requestName
   *  The name of the request. This value will be validated according to the rules
   *  explained above.
   * @return boolean
   *  TRUE if the name is legal, FALSE otherwise.
   */
  public static function isLegalRequestName($requestName) {
    return preg_match('/^[_a-zA-Z0-9\\-]+$/', $requestName) == 1;
  }
  
  /**
   * Get all loggers.
   *
   * This will load all of the loggers from the command configuration 
   * (typically commands.xml) and return them in an associative array of 
   * the form array('name' => object), where object is a FortissimoLogger
   * of some sort.
   * 
   * @return array
   *  An associative array of name => logger pairs.
   * @see FortissimoLogger
   */
  public function getLoggers() {
    return $this->getFacility('logger');
  }
  
  /**
   * Get all caches.
   *
   * This will load all of the caches from the command configuration 
   * (typically commands.xml) and return them in an associative array of 
   * the form array('name' => object), where object is a FortissimoRequestCache
   * of some sort.
   * 
   * @return array
   *  An associative array of name => logger pairs.
   * @see FortissimoRequestCache
   */
  public function getCaches() {
    return $this->getFacility('cache');
  }
  
  /**
   * Internal helper function.
   *
   * @param string $type
   *  The type of item to retrieve. Essentially, this is treated like a selector.
   * @param array 
   *  An associative array of the form <code>array('name' => object)</code>, where
   *  the object is an instance of the respective 'invoke' class.
   */
  protected function getFacility($type = 'logger') {
    $facilities = array();
    $fqp = $this->config->branch()->top($type);
    foreach ($fqp as $facility) {
      $name = $facility->attr('name');
      $klass = $facility->attr('invoke');
      $params = $this->getParams($facility);
      
      $facilities[$name] = new $klass($params);
    }
    return $facilities;
  }
  
  /**
   * Get the parameters for a facility such as a logger or a cache.
   *
   * @param QueryPath $logger
   *  Configuration for the given facility.
   * @return array
   *  An associative array of param name/values. <param name="foo">bar</param>
   *  becomes array('foo' => 'bar').
   */
  protected function getParams(QueryPath $facility) {
    $res = array();
    $params = $facility->find('param');
    if ($params->size() > 0) {
      foreach ($params as $item) {
        $res[$item->attr('name')] = $item->text();
      }
    }
    return $res;
  }
  
  /**
   * Given a request name, retrieves a request queue.
   *
   * The queue (in the form of an array) contains information about what 
   * commands should be run, and in what order.
   *
   * @param string $requestName
   *  The name of the request
   * @return FortissimoRequest 
   *  A queue of commands that need to be executed. See {@link createCommandInstance()}.
   * @throws FortissimoRequestNotFoundException
   *  If no such request is found, or if the request is malformed, and exception is 
   *  thrown. This exception should be considered fatal, and a 404 error should be 
   *  returned.
   */
  public function getRequest($requestName) {
    
    // Protection against attempts at request hacking.
    if (!self::isLegalRequestName($requestName))  {
      throw new FortissimoRequestNotFoundException('Illegal request name.');
    }
    
    // We know that per request, we only need to find one request, so we 
    // defer request lookups until we know the specific request we are after.
    $request = $this->config->top()->find('commands>request[name="' . $requestName . '"]');
    if ($request->size() == 0) {
      // This should be treated as a 404.
      throw new FortissimoRequestNotFoundException(sprintf('Request %s not found', $requestName));
    }
    
    // Determine whether the request supports caching.
    $cache = $request->attr('cache');
    // FIXME: This should support true, t, yes, y.
    $isCaching = isset($cache) && strtolower($cache) == 'true';
    
    // Once we have the request, find out what commands we need to execute.
    $commands = array();
    $chain = $request->branch()->children('cmd');
    if ($chain->size() > 0) {
      foreach ($chain as $cmd) {
        if ($cmd->hasAttr('group')) {
          $gr = $cmd->attr('group');
          if (!self::isLegalRequestName($gr)) {
            throw new FortissimoRequestNotFoundException('Illegal group name.');
          }
          // Handle group importing.
          $this->importGroup($gr, $commands);
        }
        else {
          $commands[] = $this->createCommandInstance($cmd);
        }
      }
    }
    
    $request = new FortissimoRequest($commands);
    $request->setCaching($isCaching);
    
    return $request;
  }
  
  /**
   * Import a group into the current request context.
   *
   * @param string $groupName
   *  Name of the group to import.
   * @param array &$commands
   *  Reference to an array of commands. The group commands will be appended to 
   *  this array.
   */
  protected function importGroup($groupName, &$commands) {
    //$group = $this->config->branch()->top()->find('group[name=' . $groupName . ']');
    $groups = $this->config->branch()->top()->find('commands>group');
    $group = NULL;
    foreach ($groups as $g) {
      if ($g->attr('name') == $groupName) {
        $group = $g;
        break;
      }
    }

    if (!isset($group)) {
      throw new FortissimoException(sprintf('No group found with name %s.', $groupName));
    }
    
    foreach ($group->children('cmd') as $cmd) {
      $commands[] = $this->createCommandInstance($cmd);
      
    }
  }
  
  /**
   * Create a command instance.
   *
   * Retrieve command information from the configuration file and transform these
   * into an internal data structure.
   *
   * @param QueryPath $cmd
   *  QueryPath object wrapping the command.
   * @return array
   *  An array with the following keys:
   *  - name: Name of the command
   *  - class: Name of the class
   *  - instance: An instance of the class
   *  - params: Parameter information. Note that the application must take this 
   *    information and correctly populate the parameters at execution time.
   *    Parameter information is returned as an associative array of arrays:
   *    <?php $param['name'] => array('from' => 'src:name', 'value' => 'default value'); ?>
   * @throws FortissimoException
   *  In the event that a paramter does not have a name, an exception is thrown.
   */
  protected function createCommandInstance(QueryPath $cmd) {
    $class = $cmd->attr('invoke');
    $cache = strtolower($cmd->attr('cache'));
    $caching =  (isset($cache) && $cache == 'true');
    
    if (empty($class))
      throw new FortissimoConfigException('Command is missing its "invoke" attribute.');
    
    $name = $cmd->hasAttr('name') ? $cmd->attr('name') : $class;
    
    $params = array();
    foreach ($cmd->branch()->children('param') as $param) {
      $pname = $param->attr('name');
      if (empty($pname)) {
        throw new FortissimoException('Parameter is missing name attribute.');
      }
      $params[$pname] = array(
        'from' => $param->attr('from'), // May be NULL.
        'value' => $param->text(), // May be empty.
      );
    }
    
    $inst = new $class($name, $caching);
    return array(
      'isCaching' => $caching
      'name' => $name,
      'class' => $class,
      'instance' => $inst,
      'params' => $params,
    );
  }
  
  /**
   * Get the configuration information as a QueryPath object.
   *
   * @return QueryPath
   *  The configuration information wrapped in a QueryPath object.
   */
  public function getConfig() {
    return $this->config->top();
  }

}

/**
 * Tracks context information over the lifecycle of a request's execution.
 *
 * An execution context is passed from command to command during the course of 
 * a request's execution. State information is inserted into the context by 
 * various commands. Certain commands may also take data out of the context, though
 * this operation is not without its risks. Finally, objects may use information
 * found in the context, either to perform some operation (writing data to 
 * the client) or to modify the context data.
 */
class FortissimoExecutionContext implements IteratorAggregate {
  
  // Why do we create a class that is basically a thin wrapper around an array?
  // Three reasons:
  // 1. It gives us the ability to control access to the objects in the context.
  // 2. It gives us the ability to add validation and other features
  // 3. It eliminates the need to do overt pass-by-reference of a context array,
  //   which is likely to cause confusion with less experienced developers.
  // However, we do provide the to/from array methods to allow developers to make
  // use of the richer array library without our re-inventing the wheel.
  
  protected $data = NULL;
  protected $logger = NULL;
  /** Command cache. */
  protected $cache = array();
  protected $caching = FALSE;
  
  /**
   * Create a new context.
   *
   * @param array $initialContext
   *  An associative array of context pairs.
   * @param FortissimoLoggerManager $logger
   *  The logger.
   */
  public function __construct($initialContext = array(), FortissimoLoggerManager $logger = NULL ) {
    if ($initialContext instanceof FortissimoExecutionContext) {
      $this->data = $initialContext->toArray();
    }
    else {
      $this->data = $initialContext;
    }
    
    if (isset($logger)) {
      $this->logger = $logger;
    }
  }
  
  /**
   * Log a message.
   * The context should always have a hook into a logger of some sort. This method
   * passes log messages to the underlying logger.
   *
   * @param mixed $msg
   *  The message to log. This can be a string or an Exception.
   * @param string $category
   *  A category. Typically, this is a string like 'error', 'warning', etc. But 
   *  applications can customize their categories according to the underlying
   *  logger.
   * @see FortissimoLoggerManager Manages logging facilities.
   * @see FortissimoLogger Describes a logger.
   */
  public function log($msg, $category) {
    if (isset($this->logger)) {
      $this->logger->log($msg, $category);
    }
  }
  
  /**
   * Check if the context has an item with the given name.
   *
   * @param string $name
   *  The name of the item to check for.
   */
  public function has($name) {
    return isset($this->data[$name]);
  }
  
  /**
   * Get the size of the context.
   *
   * @return int
   *  Number of items in the context.
   */
  public function size() {
    return count($this->data);
  }
  
  /**
   * Add a new name/value pair to the context.
   *
   * This will replace an existing entry with the same name. To check before
   * writing, use {@link has()}.
   *
   * @param string $name
   *  The name of the item to add.
   * @param mixed $value
   *  Some value to add. This can be a primitive, an object, or a resource. Note
   *  that storing resources is not serializable.
   */
  public function add($name, $value) {
    $this->data[$name] = $value;
  }
  //public function put($name, $value) {$this->add($name, $value);}
  
  /**
   * Add all values in the array.
   *
   * This will replace any existing entries with the same name.
   *
   * @param array $array
   *  Array of values to merge into the context.
   */
  public function addAll($array) {
    $this->data = $array + $this->data;
  }
  
  /**
   * Get a value by name.
   * @return mixed
   *  The value in the array, or NULL if $name was not found.
   */
  public function get($name) {
    return $this->data[$name];
  }
  
  /**
   * Remove an item from the context.
   *
   * @param string $name
   *  The thing to remove.
   */
  public function remove($name) {
    unset($this->data[$name]);
  }
  
  /**
   * Convert the context to an array.
   *
   * @return array
   *  Associative array of name/value pairs.
   */
  public function toArray() {
    return $this->data;
  }
  
  /**
   * Replace the current context with the values in the given array.
   *
   * @param array $contextArray
   *  An array of new name/value pairs. The old context will be destroyed.
   */
  public function fromArray($contextArray) {
    $this->data = $contextArray;
  }

  /**
   * Get an iterator of the execution context.
   *
   * @return Iterator
   *  The iterator of each item in the execution context.
   */
  public function getIterator() {
    // Does this work?
    return new ArrayIterator($this->data);
  }
}

/**
 * Manage caches.
 *
 * This manages top-level {@link FortissimoRequestCache}s. Just as with 
 * {@link FortissimoLoggerManager}, a FortissimoCacheManager can manage
 * multiple caches. It will procede from cache to cache in order until it
 * finds a hit. (Order is determined by the order returned from the 
 * configuration object.)
 *
 * Front-line Fortissimo caching is optimized for string-based values. You can,
 * of course, serialize values and store them in the cache. However, the 
 * serializing and de-serializing is left up to the implementor.
 *
 * Keys may be hashed for optimal storage in the database. Values may be optimized
 * for storage in the database. All details of caching algorithms, caching style
 * (e.g. time-based, LRU, etc.) is handled by the low-level caching classes.
 *
 * @see FortissimoRequestCache For details on caching.
 */
class FortissimoCacheManager {
  protected $caches = NULL;
  
  public function __construct($caches) {
    $this->caches = $caches;
  }
  
  /**
   * Given a name, retrieve the cache.
   *
   * @return FortissimoRequestCache
   * If there is a cache with this name, the cache object will be 
   * returned. Otherwise, this will return NULL.
   */
  public function getCacheByName($name) {
    return $this->caches[$name];
  }
  
  /**
   * Get a value from the caches.
   *
   * This will read sequentially through each defined cache until it
   * finds a match. If no match is found, this will return NULL. Otherwise, it
   * will return the value.
   */
  public function get($key) {
    foreach ($this->caches as $n => $cache) {
      $res = $cache->get($key);
      
      // Short-circuit if we find a value.
      if (isset($res)) return $res;
    }
  }
  
  /**
   * Store a value in a cache.
   *
   * This will write a value to a cache. If a cache name is given
   * as the third parameter, then that named cache will be used.
   *
   * If no cache is named, the value will be stored in the first available cache.
   *
   * If no cahce is found, this will silently continue. If a name is given, but the
   * named cache is not found, the next available cache will be used.
   *
   * @param string $key
   *  The cache key
   * @param string $value
   *  The value to store
   * @param string $cache
   *  The name of the cache to store the value in. If not given, the cache 
   *  manager will store the item wherever it is most convenient.
   */
  public function set($key, $value, $cache = NULL) {
    
    // If a named cache key is found, set:
    if (isset($cache) && isset($this->caches[$cache])) {
      return $this->caches[$cache]->set($key, $value);
    }
    
    // XXX: Right now, we just use the first item in the cache:
    $keys = array_keys($this->caches);
    if (count($keys) > 0) {
      return $this->caches[$keys[0]]->set($key, $value);
    }
  }
  
  /**
   * Check whether the value is available in a cache.
   *
   * Note that in most cases, running {@link has()} before running
   * {@link get()} will result in the same access being run twice. For 
   * performance reasons, you are probably better off calling just
   * {@link get()} if you are accessing a value.
   *
   * @param string $key
   *  The key to check for.
   * @return boolean
   *  TRUE if the key is found in the cache, false otherwise.
   */
  public function has($key) {
    foreach ($this->caches as $n => $cache) {
      $res = $cache->has($key);
      
      // Short-circuit if we find a value.
      if ($res) return $res;
    }
  }
  
  /**
   * Check which cache (if any) contains the given key.
   *
   * If you are just trying to retrieve a cache value, use {@link get()}.
   * You should use this only if you are trying to determine which underlying 
   * cache holds the given value.
   *
   * @param string $key
   *   The key to search for.
   * @return string
   *  The name of the cache that contains this key. If the key is 
   *  not found in any cache, NULL will be returned.
   */
  public function whichCacheHas($key) {
    foreach ($this->caches as $n => $cache) {
      $res = $cache->has($key);
      
      // Short-circuit if we find a value.
      if ($res) return $n;
    }
  }
}

/**
 * Manage loggers for a server.
 */
class FortissimoLoggerManager {
  
  protected $loggers = NULL;
  
  /**
   * Build a new logger manager.
   *
   * @param QueryPath $config
   *  The configuration object. Typically, this is from commands.xml.
   */ 
  //public function __construct(QueryPath $config) {
  public function __construct($config) {
    // Initialize array of loggers.
    $this->loggers = &$config;
  }
  
  /**
   * Get a logger.
   *
   * @param string $name
   *  The name of the logger, as indicated in the configuration.
   * @return FortissimoLogger
   *  The logger corresponding to the name, or NULL if no such logger is found.
   */
  public function getLoggerByName($name) {
    return $this->loggers[$name];
  }
  
  /**
   * Log messages.
   *
   * @param mixed $msg
   *  A string or an Exception.
   * @param string $category
   *  A string indicating what type of message is
   *  being logged. Standard values for this are:
   *  - error
   *  - warning
   *  - info
   *  - debug
   *  Your application may use whatever values are
   *  fit. However, underlying loggers may interpret
   *  these differently. 
   */
  public function log($msg, $category) {
    foreach ($this->loggers as $name => $logger) {
      $logger->rawLog($msg, $severity);
    }
  }
  
}

/**
 * The FOIL logger captures log messages which can later be retrieved.
 *
 * Log entries can be injected into the output by retrieving a list
 * of log messages with {@link getMessages()}, and then displaying them,
 * or by simply calling {@link printMessages()}.
 */
class FortissimoOutputInjectionLogger extends FortissimoLogger {
  
  protected $logItems = array();
  
  public function getMessages() {
    return $this->logItems;
  }
  
  public function printMessages() {
    print implode('', $this->logItems);
  }
  
  public function init() {}
  public function log($message, $category) {
    $this->logItems[] = sprintf('<div class="log-item %s">%s</div>', $category, $message);
  }
}

/**
 * A cache for command or request output.
 *
 * This provides a caching facility for the output of entire requests, or for the 
 * output of commands. This cache is used for high-level data caching from within
 * the application.
 *
 * The Fortissimo configuration, typically found in commands.xml, must specify which
 * requests and commands are cacheable.
 *
 * The underlying caching implementation determines how things are cached, for how
 * long, and what the expiration conditions are. It will also determine where data
 * is cached.
 *
 * External caches, like Varnish or Squid, tend not to use this mechanism. Internal
 * mechanisms like APC or custom database caches would use this mechanism. Memcached
 * would also use this mechanism, if appropriate.
 */
interface FortissimoRequestCache {
  /**
   * Add an item to the cache.
   *
   * @param string $key
   *  A short (<255 character) string that will be used as the key. This is short
   *  so that database-based caches can optimize for varchar fields instead of 
   *  text fields.
   * @param string $value
   *  The string that will be stored as the value.
   */
  public function set($key, $value);
  /**
   * Clear the entire cache.
   */
  public function clear();
  /**
   * Delete an item from the cache.
   *
   * @param string $key
   *  The key to remove from the cache.
   */
  public function delete($key);
  /**
   * Retrieve an item from the cache.
   *
   * @param string $key
   *  The key to return.
   * @return mixed
   *  The string found in the cache, or NULL if nothing was found.
   */
  public function get($key);
}

/**
 * A logger responsible for logging messages to a particular destination.
 *
 */
abstract class FortissimoLogger {
  
  /**
   * The parameters for this logger.
   */
  protected $params = NULL;
  
  /**
   * Construct a new logger instance.
   *
   * @param array $params
   *   An associative array of name/value pairs.
   */
  public function __construct($params = array()) {
    $this->params = $params;
  }
  
  /**
   * Handle raw log requests.
   *
   * This handles the transformation of objects (Exceptions)
   * into loggable strings. 
   *
   * @param mixed $message
   *  Typically, this is an Exception, some other object, or a string.
   *  This method normalizes the $message, converting it to a string
   *  before handing it off to the {@link log()} function.
   * @param string $category
   *  This message is passed on to the logger.
   */
  public function rawLog($message, $category) {
    if ($message instanceof Exception) {
      $buffer = $message->getMessage();
      $buffer .= $message->getTraceAsString();
      
    }
    elseif (is_object($message)) {
      $buffer = $mesage->toString();
    }
    else {
      $buffer = $message;
    }
    $this->log($buffer, $category);
    return;
    
  }
  
  /**
   * Initialize the logger.
   *
   * This will happen once per server construction (typically
   * once per request), and it will occur before the command is executed.
   */
  public abstract function init();
  
  /**
   * Log a message.
   *
   * @param string $msg
   *  The message to log.
   * @param string $category
   *  The log message category. Typical values are 
   *  - warning
   *  - error
   *  - info
   *  - debug
   */
  public abstract function log($msg, $severity);
  
}

/**
 * Indicates that a condition has been met that necessitates interrupting the command execution chain.
 *
 * This exception is not necessarily intended to indicate that something went 
 * wrong, but only htat a condition has been satisfied that warrants the interrupting
 * of the current chain of execution.
 *
 * Note that commands that throw this exception are responsible for responding
 * to the user agent. Otherwise, no output will be generated.
 *
 * Examples of cases where this might be desirable:
 * - Application should redirect (302, 304, etc.) user to another page.
 * - User needs to be prompted to log in, using HTTP auth, before continuing.
 */
class FortissimoInterrupt extends Exception {}
/**
 * Indicates that a fatal error has occured.
 *
 * This is the Fortissimo exception with the strongest implications. It indicates
 * that not only has an error occured, but it is of such a magnitude that it 
 * precludes the ability to continue processing. These should be used sparingly,
 * as they prevent the chain of commands from completing.
 *
 * Examples:
 * - A fatal error has occurred, and a 500-level error should be returned to the user.
 * - Access is denied to the user.
 * - A request name cannot be found.
 */
class FortissimoInterruptException extends Exception {}
/**
 * General Fortissimo exception.
 *
 * This should be thrown when Fortissimo encounters an exception that should be
 * logged and stored, but should not interrupt the execution of a command.
 */
class FortissimoException extends Exception {}
/**
 * Configuration error.
 */
class FortissimoConfigurationException extends FortissimoException {}
/**
 * Request was not found.
 */
class FortissimoRequestNotFoundException extends FortissimoException {}

/**
 * Forward a request to another request.
 *
 * This special type of interrupt can be thrown to redirect a request mid-stream
 * to another request. The context passed in will be used to pre-seed the context
 * of the next request.
 */
class FortissimoForwardRequest extends FortissimoInterrupt {
  protected $destination;
  protected $cxt;
  
  /**
   * Construct a new forward request.
   *
   * The information in this forward request will be used to attempt to terminate
   * the current request, and continue processing by forwarding on to the 
   * named request.
   *
   * @param string $requestName
   *  The name of the request that this should forward to.
   * @param FortissimoExecutionContext $cxt
   *  The context. IF THIS IS PASSED IN, the next request will continue using this
   *  context. IF THIS IS NOT PASSED OR IS NULL, the next request will begin afresh
   *  with an empty context.
   */
  public function __construct($requestName, FortissimoExecutionContext $cxt = NULL) {
    $this->destination = $requestName;
    $this->cxt = $cxt;
    parent::__construct('Request forward.');
  }
  
  /**
   * Get the name of the desired destination request.
   *
   * @return string
   *  A request name.
   */
  public function destination() {
    return $this->destination;
  }
  
  /**
   * Retrieve the context.
   *
   * @return FortissimoExecutionContext
   *  The context as it was at the point when the request was interrupted.
   */
  public function context() {
    return $this->cxt;
  }
}