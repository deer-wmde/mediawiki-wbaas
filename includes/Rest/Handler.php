<?php

namespace MediaWiki\Rest;

use DateTime;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\Validator\BodyValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\NullBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\Session\Session;
use Wikimedia\Message\MessageValue;

/**
 * Base class for REST route handlers.
 *
 * @stable to extend.
 */
abstract class Handler {

	/**
	 * @see Validator::KNOWN_PARAM_SOURCES
	 */
	public const KNOWN_PARAM_SOURCES = Validator::KNOWN_PARAM_SOURCES;

	/**
	 * @see Validator::PARAM_SOURCE
	 */
	public const PARAM_SOURCE = Validator::PARAM_SOURCE;

	/**
	 * @see Validator::PARAM_DESCRIPTION
	 */
	public const PARAM_DESCRIPTION = Validator::PARAM_DESCRIPTION;

	/** @var Router */
	private $router;

	/** @var RequestInterface */
	private $request;

	/** @var Authority */
	private $authority;

	/** @var array */
	private $config;

	/** @var ResponseFactory */
	private $responseFactory;

	/** @var array|null */
	private $validatedParams;

	/** @var mixed|null */
	private $validatedBody;

	/** @var ConditionalHeaderUtil */
	private $conditionalHeaderUtil;

	/** @var HookContainer */
	private $hookContainer;

	/** @var Session */
	private $session;

	/** @var HookRunner */
	private $hookRunner;

	/**
	 * Initialise with dependencies from the Router. This is called after construction.
	 * @param Router $router
	 * @param RequestInterface $request
	 * @param array $config
	 * @param Authority $authority
	 * @param ResponseFactory $responseFactory
	 * @param HookContainer $hookContainer
	 * @param Session $session
	 * @internal
	 */
	final public function init( Router $router, RequestInterface $request, array $config,
		Authority $authority, ResponseFactory $responseFactory, HookContainer $hookContainer,
		Session $session
	) {
		$this->router = $router;
		$this->request = $request;
		$this->authority = $authority;
		$this->config = $config;
		$this->responseFactory = $responseFactory;
		$this->hookContainer = $hookContainer;
		$this->hookRunner = new HookRunner( $hookContainer );
		$this->session = $session;
		$this->postInitSetup();
	}

	/**
	 * Returns the path this handler is bound to, including path variables.
	 *
	 * @return string
	 */
	public function getPath(): string {
		return $this->getConfig()['path'];
	}

	/**
	 * Get the Router. The return type declaration causes it to raise
	 * a fatal error if init() has not yet been called.
	 * @return Router
	 */
	protected function getRouter(): Router {
		return $this->router;
	}

	/**
	 * Get the URL of this handler's endpoint.
	 * Supports the substitution of path parameters, and additions of query parameters.
	 *
	 * @see Router::getRouteUrl()
	 *
	 * @param string[] $pathParams Path parameters to be injected into the path
	 * @param string[] $queryParams Query parameters to be attached to the URL
	 *
	 * @return string
	 */
	protected function getRouteUrl( $pathParams = [], $queryParams = [] ): string {
		$path = $this->getConfig()['path'];
		return $this->router->getRouteUrl( $path, $pathParams, $queryParams );
	}

	/**
	 * URL-encode titles in a "pretty" way.
	 *
	 * Keeps intact ;@$!*(),~: (urlencode does not, but wfUrlencode does).
	 * Encodes spaces as underscores (wfUrlencode does not).
	 * Encodes slashes (wfUrlencode does not, but keeping them messes with REST paths).
	 * Encodes pluses (this is not necessary, and may change).
	 *
	 * @see wfUrlencode
	 *
	 * @param string $title
	 *
	 * @return string
	 */
	protected function urlEncodeTitle( $title ) {
		$title = str_replace( ' ', '_', $title );
		$title = urlencode( $title );

		// %3B_a_%40_b_%24_c_%21_d_%2A_e_%28_f_%29_g_%2C_h_~_i_%3A
		$replace = [ '%3B', '%40', '%24', '%21', '%2A', '%28', '%29', '%2C', '%7E', '%3A' ];
		$with = [ ';', '@', '$', '!', '*', '(', ')', ',', '~', ':' ];

		return str_replace( $replace, $with, $title );
	}

	/**
	 * Get the current request. The return type declaration causes it to raise
	 * a fatal error if init() has not yet been called.
	 *
	 * @return RequestInterface
	 */
	public function getRequest(): RequestInterface {
		return $this->request;
	}

	/**
	 * Get the current acting authority. The return type declaration causes it to raise
	 * a fatal error if init() has not yet been called.
	 *
	 * @since 1.36
	 * @return Authority
	 */
	public function getAuthority(): Authority {
		return $this->authority;
	}

	/**
	 * Get the configuration array for the current route. The return type
	 * declaration causes it to raise a fatal error if init() has not
	 * been called.
	 *
	 * @return array
	 */
	public function getConfig(): array {
		return $this->config;
	}

	/**
	 * Get the ResponseFactory which can be used to generate Response objects.
	 * This will raise a fatal error if init() has not been
	 * called.
	 *
	 * @return ResponseFactory
	 */
	public function getResponseFactory(): ResponseFactory {
		return $this->responseFactory;
	}

	/**
	 * Get the Session.
	 * This will raise a fatal error if init() has not been
	 * called.
	 *
	 * @return Session
	 */
	public function getSession(): Session {
		return $this->session;
	}

	/**
	 * Validate the request parameters/attributes and body. If there is a validation
	 * failure, a response with an error message should be returned or an
	 * HttpException should be thrown.
	 *
	 * @stable to override
	 * @param Validator $restValidator
	 * @throws HttpException On validation failure.
	 */
	public function validate( Validator $restValidator ) {
		$paramSettings = $this->getParamSettings();
		$legacyValidatedBody = $restValidator->validateBody( $this->request, $this );

		$this->validatedParams = $restValidator->validateParams( $paramSettings );

		if ( $legacyValidatedBody !== null ) {
			// TODO: warn if $bodyParamSettings is not empty
			// TODO: trigger a deprecation warning
			$this->validatedBody = $legacyValidatedBody;
		} else {
			$this->validatedBody = $restValidator->validateBodyParams( $paramSettings );

			// If there is a body, check if it contains extra fields.
			if ( $this->getRequest()->hasBody() ) {
				$this->detectExtraneousBodyFields( $restValidator );
			}
		}

		$this->postValidationSetup();
	}

	/**
	 * Subclasses may override this to disable or modify checks for extraneous
	 * body fields.
	 *
	 * @since 1.42
	 * @stable to override
	 * @param Validator $restValidator
	 * @throws HttpException On validation failure.
	 */
	protected function detectExtraneousBodyFields( Validator $restValidator ) {
		$parsedBody = $this->getRequest()->getParsedBody();

		if ( !$parsedBody ) {
			// nothing to do
			return;
		}

		$restValidator->detectExtraneousBodyFields(
			$this->getParamSettings(),
			$parsedBody
		);
	}

	/**
	 * Check the session (and session provider)
	 * @throws HttpException on failed check
	 * @internal
	 */
	public function checkSession() {
		if ( !$this->session->getProvider()->safeAgainstCsrf() ) {
			if ( $this->requireSafeAgainstCsrf() ) {
				throw new LocalizedHttpException(
					new MessageValue( 'rest-requires-safe-against-csrf' ),
					400
				);
			}
		} elseif ( !empty( $this->validatedBody['token'] ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'rest-extraneous-csrf-token' ),
				400
			);
		}
	}

	/**
	 * Get a ConditionalHeaderUtil object.
	 *
	 * On the first call to this method, the object will be initialized with
	 * validator values by calling getETag(), getLastModified() and
	 * hasRepresentation().
	 *
	 * @return ConditionalHeaderUtil
	 */
	protected function getConditionalHeaderUtil() {
		if ( $this->conditionalHeaderUtil === null ) {
			$this->conditionalHeaderUtil = new ConditionalHeaderUtil;
			$this->conditionalHeaderUtil->setValidators(
				$this->getETag(),
				$this->getLastModified(),
				$this->hasRepresentation()
			);
		}
		return $this->conditionalHeaderUtil;
	}

	/**
	 * Check the conditional request headers and generate a response if appropriate.
	 * This is called by the Router before execute() and may be overridden.
	 *
	 * @stable to override
	 *
	 * @return ResponseInterface|null
	 */
	public function checkPreconditions() {
		$status = $this->getConditionalHeaderUtil()->checkPreconditions( $this->getRequest() );
		if ( $status ) {
			$response = $this->getResponseFactory()->create();
			$response->setStatus( $status );
			return $response;
		}

		return null;
	}

	/**
	 * Apply verifier headers to the response, per RFC 7231 §7.2.
	 * This is called after execute() returns.
	 *
	 * For GET and HEAD requests, the default behavior is to set the ETag and
	 * Last-Modified headers based on the values returned by getETag() and
	 * getLastModified() when they were called before execute() was run.
	 *
	 * Other request methods are assumed to be state-changing, so no headers
	 * will be set per default.
	 *
	 * This may be overridden to modify the verifier headers sent in the response.
	 * However, handlers that modify the resource's state would typically just
	 * set the ETag and Last-Modified headers in the execute() method.
	 *
	 * @stable to override
	 *
	 * @param ResponseInterface $response
	 */
	public function applyConditionalResponseHeaders( ResponseInterface $response ) {
		$method = $this->getRequest()->getMethod();
		if ( $method === 'GET' || $method === 'HEAD' ) {
			$this->getConditionalHeaderUtil()->applyResponseHeaders( $response );
		}
	}

	/**
	 * Apply cache control to enforce privacy.
	 *
	 * @param ResponseInterface $response
	 */
	public function applyCacheControl( ResponseInterface $response ) {
		// NOTE: keep this consistent with the logic in OutputPage::sendCacheControl

		// If the response sets cookies, it must not be cached in proxies.
		// If there's an active cookie-based session (logged-in user or anonymous user with
		// session-scoped cookies), it is not safe to cache either, as the session manager may set
		// cookies in the response, or the response itself may vary on user-specific variables,
		// for example on private wikis where the 'read' permission is restricted. (T264631)
		if ( $response->getHeaderLine( 'Set-Cookie' ) || $this->getSession()->isPersistent() ) {
			$response->setHeader( 'Cache-Control', 'private,must-revalidate,s-maxage=0' );
		}

		if ( !$response->getHeaderLine( 'Cache-Control' ) ) {
			$rqMethod = $this->getRequest()->getMethod();
			if ( $rqMethod !== 'GET' && $rqMethod !== 'HEAD' ) {
				// Responses to requests other than GET or HEAD should not be cacheable per default.
				$response->setHeader( 'Cache-Control', 'private,no-cache,s-maxage=0' );
			}
		}
	}

	/**
	 * Fetch ParamValidator settings for parameters
	 *
	 * Every setting must include self::PARAM_SOURCE to specify which part of
	 * the request is to contain the parameter.
	 *
	 * Can be used for validating parameters inside an application/x-www-form-urlencoded or
	 * multipart/form-data POST body (i.e. parameters which would be present in PHP's $_POST
	 * array). For validating other kinds of request bodies, override getBodyValidator().
	 *
	 * @stable to override
	 *
	 * @return array[] Associative array mapping parameter names to
	 *  ParamValidator settings arrays
	 */
	public function getParamSettings() {
		return [];
	}

	/**
	 * Returns an OpenAPI Operation Object specification structure as an associative array.
	 *
	 * @see https://swagger.io/specification/#operation-object
	 *
	 * Per default, this will contain information about the supported parameters, as well as
	 * the response for status 200.
	 *
	 * Subclasses may override this to provide additional information.
	 *
	 * @since 1.42
	 * @stable to override
	 *
	 * @param string $method The HTTP method to produce a spec for ("get", "post", etc).
	 *        Useful for handlers that behave differently depending on the
	 *        request method.
	 *
	 * @return array
	 */
	public function getOpenApiSpec( string $method ): array {
		$parameters = [];

		// XXX: Maybe we want to be able to define a spec file in the route definition?
		// NOTE: the route definition may not be loaded when this is called before init()!

		foreach ( $this->getParamSettings() as $name => $paramSetting ) {
			$param = Validator::getParameterSpec(
				$name,
				$paramSetting
			);

			$location = $param['in'];
			if ( $location !== 'post' && $location !== 'body' ) {
				// 'post' and 'body' are handled in getRequestSpec()
				// but others are added as normal parameters
				$parameters[] = $param;
			}
		}

		$spec = [
			'parameters' => $parameters,
			'responses' => $this->getResponseSpec(),
		];

		$requestBody = $this->getRequestSpec();
		if ( $requestBody ) {
			$spec['requestBody'] = $requestBody;
		}

		return $spec;
	}

	/**
	 * Returns an OpenAPI Request Body Object specification structure as an associative array.
	 * @see https://swagger.io/specification/#request-body-object
	 *
	 * Per default, this calls getBodyValidator() to get a SchemaValidator,
	 * and then calls getBodySpec() on it.
	 * If no SchemaValidator is supported, this returns null;
	 *
	 * Subclasses may override this to provide additional information about the structure of responses.
	 *
	 * @stable to override
	 * @return ?array
	 */
	protected function getRequestSpec(): ?array {
		$request = [];

		// XXX: support additional content types?!
		try {
			$validator = $this->getBodyValidator( 'application/json' );

			// TODO: all validators should support getBodySpec()!
			if ( $validator instanceof JsonBodyValidator ) {
				$schema = $validator->getOpenAPISpec();

				if ( $schema !== [] ) {
					$request['content']['application/json']['schema'] = $schema;
				}
			}
		} catch ( HttpException $ex ) {
			// JSON not supported, ignore.
		}

		return $request ?: null;
	}

	/**
	 * Returns an OpenAPI Schema Object specification structure as an associative array.
	 * @see https://swagger.io/specification/#schema-object
	 *
	 * Returns null per default. Subclasses that return a JSON response should
	 * implement this method to return a schema of the response body.
	 *
	 * @stable to override
	 * @return ?array
	 */
	protected function getResponseBodySchema(): ?array {
		return null;
	}

	/**
	 * Returns an OpenAPI Responses Object specification structure as an associative array.
	 * @see https://swagger.io/specification/#responses-object
	 *
	 * Per default, this will contain basic information response for status 200, 400, and 500.
	 * The getResponseBodySchema() method is used to determine the structure of the response for status 200.
	 *
	 * Subclasses may override this to provide additional information about the structure of responses.
	 *
	 * @stable to override
	 * @return array
	 */
	protected function getResponseSpec(): array {
		$ok = [ 'description' => 'OK' ];

		$bodySchema = $this->getResponseBodySchema();

		if ( $bodySchema ) {
			$ok['content']['application/json']['schema'] = $bodySchema;
		}

		// XXX: we should add info about redirects, and maybe a default for errors?
		return [
			'200' => $ok,
			'400' => [ '$ref' => '#/components/responses/GenericErrorResponse' ],
			'500' => [ '$ref' => '#/components/responses/GenericErrorResponse' ],
		];
	}

	/**
	 * Fetch the BodyValidator
	 *
	 * @stable to override
	 *
	 * @param string $contentType Content type of the request.
	 * @return BodyValidator A {@see NullBodyValidator} in this default implementation
	 * @throws HttpException It's possible to fail early here when e.g. $contentType is unsupported,
	 *  or later when {@see BodyValidator::validateBody} is called
	 */
	public function getBodyValidator( $contentType ) {
		// TODO: Create a JsonBodyValidator if getParamSettings() returns body params.
		// XXX: also support multipart/form-data and application/x-www-form-urlencoded?
		return new NullBodyValidator();
	}

	/**
	 * Fetch the validated parameters. This must be called after validate() is
	 * called. During execute() is fine.
	 *
	 * @return array Array mapping parameter names to validated values
	 * @throws \RuntimeException If validate() has not been called
	 */
	public function getValidatedParams() {
		if ( $this->validatedParams === null ) {
			throw new \RuntimeException( 'getValidatedParams() called before validate()' );
		}
		return $this->validatedParams;
	}

	/**
	 * Fetch the validated body
	 * @return mixed|null Value returned by the body validator, or null if validate() was
	 *  not called yet, validation failed, there was no body, or the body was form data.
	 */
	public function getValidatedBody() {
		return $this->validatedBody;
	}

	/**
	 * Returns the parsed body of the request.
	 * Should only be called if $request->hasBody() returns true.
	 *
	 * The default implementation handles application/x-www-form-urlencoded
	 * and multipart/form-data by calling $request->getPostParams().
	 *
	 * The default implementation handles application/json by parsing
	 * the body content as JSON. Only object structures (maps) are supported,
	 * other types will trigger an HttpException with status 400.
	 *
	 * Other content types will trigger a HttpException with status 415 per
	 * default.
	 *
	 * Subclasses may override this method to support parsing additional
	 * content types or to disallow content types by throwing an HttpException
	 * with status 415. Subclasses may also return null to indicate that they
	 * support reading the content, but intend to handle it as an unparsed
	 * stream in their implementation of the execute() method.
	 *
	 * @since 1.42
	 *
	 * @throws HttpException If the content type is not supported or the content
	 *         is malformed.
	 *
	 * @return array|null The body content represented as an associative array,
	 *         or null if the request body is accepted unparsed.
	 */
	public function parseBodyData( RequestInterface $request ): ?array {
		// Parse the body based on its content type
		$contentType = $request->getBodyType();

		// HACK: If the Handler uses a custom BodyValidator, the
		// getBodyValidator() is also responsible for checking whether
		// the content type is valid, and for parsing the body.
		// See T359149.
		$bodyValidator = $this->getBodyValidator( $contentType ?? 'unknown/unknown' );
		if ( !$bodyValidator instanceof NullBodyValidator ) {
			// TODO: Trigger a deprecation warning.
			return null;
		}

		switch ( $contentType ) {
			case 'application/x-www-form-urlencoded':
			case 'multipart/form-data':
				return $request->getPostParams();
			case 'application/json':
				$jsonStream = $request->getBody();
				$parsedBody = json_decode( "$jsonStream", true );
				if ( !is_array( $parsedBody ) ) {
					throw new LocalizedHttpException(
						new MessageValue(
							'rest-json-body-parse-error',
							[ 'not a valid JSON object' ]
						),
						400
					);
				}
				return $parsedBody;
			case null:
				// Specifying no Content-Type is fine if the body is empty
				if ( $request->getBody()->getSize() === 0 ) {
					return null;
				}
				// no break, else fall through to the error below.
			default:
				throw new LocalizedHttpException(
					new MessageValue( 'rest-unsupported-content-type', [ $contentType ?? '(null)' ] ),
					415
				);
		}
	}

	/**
	 * Get a HookContainer, for running extension hooks or for hook metadata.
	 *
	 * @since 1.35
	 * @return HookContainer
	 */
	protected function getHookContainer() {
		return $this->hookContainer;
	}

	/**
	 * Get a HookRunner for running core hooks.
	 *
	 * @internal This is for use by core only. Hook interfaces may be removed
	 *   without notice.
	 * @since 1.35
	 * @return HookRunner
	 */
	protected function getHookRunner() {
		return $this->hookRunner;
	}

	/**
	 * The subclass should override this to provide the maximum last modified
	 * timestamp of the requested resource. This is called before execute() in
	 * order to decide whether to send a 304. If the request is going to
	 * change the state of the resource, the time returned must represent
	 * the last modification date before the change. In other words, it must
	 * provide the timestamp of the entity that the change is going to be
	 * applied to.
	 *
	 * For GET and HEAD requests, this value will automatically be included
	 * in the response in the Last-Modified header.
	 *
	 * Handlers that modify the resource and want to return a Last-Modified
	 * header representing the new state in the response should set the header
	 * in the execute() method.
	 *
	 * See RFC 7231 §7.2 and RFC 7232 §2.3 for semantics.
	 *
	 * @stable to override
	 *
	 * @return bool|string|int|float|DateTime|null
	 */
	protected function getLastModified() {
		return null;
	}

	/**
	 * The subclass should override this to provide an ETag for the current
	 * state of the requested resource. This is called before execute() in
	 * order to decide whether to send a 304. If the request is going to
	 * change the state of the resource, the ETag returned must represent
	 * the state before the change. In other words, it must identify
	 * the entity that the change is going to be applied to.
	 *
	 * For GET and HEAD requests, this ETag will also be included in the
	 * response.
	 *
	 * Handlers that modify the resource and want to return an ETag
	 * header representing the new state in the response should set the header
	 * in the execute() method. However, note that responses to PUT requests
	 * must not return an ETag unless the new content of the resource is exactly
	 * the data that was sent by the client in the request body.
	 *
	 * This must be a complete ETag, including double quotes.
	 * See RFC 7231 §7.2 and RFC 7232 §2.3 for semantics.
	 *
	 * @stable to override
	 *
	 * @return string|null
	 */
	protected function getETag() {
		return null;
	}

	/**
	 * The subclass should override this to indicate whether the resource
	 * exists. This is used for wildcard validators, for example "If-Match: *"
	 * fails if the resource does not exist.
	 *
	 * In a state-changing request, the return value of this method should
	 * reflect the state before the requested change is applied.
	 *
	 * @stable to override
	 *
	 * @return bool|null
	 */
	protected function hasRepresentation() {
		return null;
	}

	/**
	 * Indicates whether this route requires read rights.
	 *
	 * The handler should override this if it does not need to read from the
	 * wiki. This is uncommon, but may be useful for login and other account
	 * management APIs.
	 *
	 * @stable to override
	 *
	 * @return bool
	 */
	public function needsReadAccess() {
		return true;
	}

	/**
	 * Indicates whether this route requires write access.
	 *
	 * The handler should override this if the route does not need to write to
	 * the database.
	 *
	 * This should return true for routes that may require synchronous database writes.
	 * Modules that do not need such writes should also not rely on primary database access,
	 * since only read queries are needed and each primary DB is a single point of failure.
	 *
	 * @stable to override
	 *
	 * @return bool
	 */
	public function needsWriteAccess() {
		return true;
	}

	/**
	 * Indicates whether this route can be accessed only by session providers safe vs csrf
	 *
	 * The handler should override this if the route must only be accessed by session
	 * providers that are safe against csrf.
	 *
	 * A return value of false does not necessarily mean the route is vulnerable to csrf attacks.
	 * It means the route can be accessed by session providers that are not automatically safe
	 * against csrf attacks, so the possibility of csrf attacks must be considered.
	 *
	 * @stable to override
	 *
	 * @return bool
	 */
	public function requireSafeAgainstCsrf() {
		return false;
	}

	/**
	 * The handler can override this to do any necessary setup after init()
	 * is called to inject the dependencies.
	 *
	 * @stable to override
	 */
	protected function postInitSetup() {
	}

	/**
	 * The handler can override this to do any necessary setup after validate()
	 * has been called. This gives the handler an opportunity to do initialization
	 * based on parameters before pre-execution calls like getLastModified() or getETag().
	 *
	 * @stable to override
	 * @since 1.36
	 */
	protected function postValidationSetup() {
	}

	/**
	 * Execute the handler. This is called after parameter validation. The
	 * return value can either be a Response or any type accepted by
	 * ResponseFactory::createFromReturnValue().
	 *
	 * To automatically construct an error response, execute() should throw a
	 * \MediaWiki\Rest\HttpException. Such exceptions will not be logged like
	 * a normal exception.
	 *
	 * If execute() throws any other kind of exception, the exception will be
	 * logged and a generic 500 error page will be shown.
	 *
	 * @stable to override
	 *
	 * @return mixed
	 */
	abstract public function execute();
}
