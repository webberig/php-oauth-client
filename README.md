# Introduction
This project provides an OAuth 2.0 "Authorization Code Grant" client as 
described in RFC 6749, section 4.1.

The client can be controlled through a PHP API that is used from the 
application trying to access an OAuth 2.0 protected resource server. 

# Features
The following features are supported:

* "Authorization Code Grant" Profile
* Refresh Tokens

# License
Licensed under the GNU Lesser General Public License as published by the Free 
Software Foundation, either version 3 of the License, or (at your option) any 
later version.

    https://www.gnu.org/licenses/lgpl.html

This roughly means that if you write some PHP application that uses this client 
you do not need to release your application under (L)GPL as well. Refer to the 
license for the exact details.

# Application Integration
If you want to integrate this OAuth client in your application you need to 
answer some questions:

* Where am I going to store the access tokens?
* How do I make an endpoint URL available in my application that can be used as 
  a redirect URL for the callback from the authorization server?

Next to this you need OAuth client credentials from the authorization server 
and REST API documentation from the service you want to connect to. You for 
instance need to know the `authorize_endpoint`, the `token_endpoint`, the
`client_id` and `client_secret`.

As for storing access tokens, this library includes two backends. One for 
storing the tokens in a database (using the PHP PDO abstraction layer) and one 
for storing them in the user session. The first one requires some setup, the 
second one is very easy to use (no configuration) but will not allow the client 
to access data at the resource server without the session data being available. 
A more robust implementation would use the PDO backed storage. For testing 
purposes or very simple setups the session implementation makes the most sense.

For accessing the resource service a Guzzle plugin is available that will help
you with that.

The sections below will walk through all the steps you need in order to get the
client working.

## Example
In addition to this, a full example is available in the `example` directory. 
This includes `index.php` that does the token request and requests the data. 
Also a `callback.php` is included to show how to use the `Callback` API. Next
to this a `composer.json` is included for use with Composer.

## Composer
In order to easily integrate with your application it is recommended to use
Composer to install the dependencies. You need to install two libraries to 
use this library: 

* `fkooman/php-oauth-client`
* `fkooman/guzzle-bearer-auth-plugin`

Below is a simple example `composer.json` file you could use:

    {
        "name": "fkooman/my-demo-oauth-app",
        "require": {
            "fkooman/guzzle-bearer-auth-plugin": "dev-master",
            "fkooman/php-oauth-client": "dev-master"
        }
    }

## Client Configuration
You can create an client configuration object as shown below. You can fetch 
this from a configuration file in your application if desired. Below is an 
example of the generic `ClientConfig` class:

    $clientConfig = new ClientConfig(
        array(
            "authorize_endpoint" => "http://localhost/oauth/php-oauth/authorize.php",
            "client_id" => "foo",
            "client_secret" => "foobar",
            "token_endpoint" => "http://localhost/oauth/php-oauth/token.php",
        )
    );

There is also a `GoogleClientConfig` class that you can use with Google's 
`client_secrets.json` file format:
    
    // Google
    $googleClientConfig = new GoogleClientConfig(
        json_decode(file_get_contents("client_secrets.json"), true)
    );

## Initializing the API
Now you can initialize the `Api` object:

    $api = new Api("foo", $clientConfig, new SessionStorage(), new \Guzzle\Http\Client());
    
In this example we use the `SessionStorage` token storage backend. This is used 
to keep the obtained tokens in the user session. For testing purposes this is 
sufficient, for production deployments you will want to use the `PdoStorage` 
backend instead, see below.

You also need to provide an instance of Guzzle which is a HTTP client used to 
exchange authorization codes for access tokens, or use a refresh token to 
obtain a new access token.

## Requesting Tokens
In order to request tokens you need to use two methods: `Api::getAccessToken()` 
and  `Api::getAuthorizeUri()`. The first one is used to see if there is already 
a token available, the second to obtain an URL to which you have to redirect 
the browser from your application. The example below will show you how to use 
these methods.

Before you can call these methods you need to create a `Context` object to 
specify for which user you are requesting this access token and what the scope 
is you want to request at the authorization server.

    $context = new Context("john.doe@example.org", array("read"));
    
This means that you will request a token bound to `john.doe@example.org` with 
the scope `read`. The user you specify here is typically the user identifier 
you use in *your* application that wants to integrate with the OAuth 2.0 
protected resource. At your service the user can for example be 
`john.doe@example.org`. This identifier is in no way related to the identity
of the user at the remote service, it is just used for book keeping the 
access tokens.

Now you can see if an access token is already available:

    $accessToken = $api->getAccessToken($context);
    
This call returns `false` if no access token is available for this user and 
scope and none could be obtained through the backchannel using a refresh token. 
This means that there never was a token or it expired. The token can still be
revoked, but we cannot see that right now, we'll find that out when we try to
use it later.

Assuming the `getAccessToken($context)` call returns `false`, i.e.: there was 
no token, we have to obtain authorization:

    if (false === $accessToken) {
        /* no valid access token available, go to authorization server */
        header("HTTP/1.1 302 Found");
        header("Location: " . $api->getAuthorizeUri($context));
        exit;
    }

This is the simplest way if your application is not using any framework. 
If your application uses a framework you can probably use that to do "proper" 
redirect without setting the HTTP headers yourself. You should use this!

After this, the flow of this script ends and the user is redirected to the 
authorization server. Once there, the user accepts the client request and is 
redirected back to the redirection URL you registered at the OAuth 2.0 service 
provider. You also need to put some code at this callback location, see the 
next section below.

Assuming you already had an access token, i.e.: the response from 
`Api::getAccessToken()` was not `false` you can now try to get the resource. 
This example uses Guzzle as well:

    $apiUrl = 'http://www.example.org/resource';
    
    try {
        $client = new Client();
        $bearerAuth = new BearerAuth($accessToken->getAccessToken());
        $client->addSubscriber($bearerAuth);
        $response = $client->get($apiUri)->send();

        header("Content-Type: application/json");
        echo $response->getBody();
    } catch (BearerErrorResponseException $e) {
        if ("invalid_token" === $e->getBearerReason()) {
            // the token we used was invalid, possibly revoked, we throw it away
            $api->deleteAccessToken($context);
            $api->deleteRefreshToken($context);

            /* no valid access token available, go to authorization server */
            header("HTTP/1.1 302 Found");
            header("Location: " . $api->getAuthorizeUri($context));
            exit;
        }
        throw $e;
    }
    
Pay special attention to the `BearerErrorResponseException` where both the 
access token and refresh token are deleted when the access token does not work.
If that happens, the browser is redirected like in the case when there was no
token yet.

## Handling the Callback
The above situation assumed you already had a valid access token. If you didn't
you got redirected to the authorization server where you had to accept the 
request for access to your data. Assuming that all went well you will be 
redirected back the the redirection URI you registered at the OAuth 2.0 
service.

The of the `Callback` class is very similar to the `Api` class. We assume you
also create the `ClientConfig` object here, like in the `Api` case. The
contents of this file are assumed to be in `callback.php`.

    try {
        $cb = new Callback("foo", $clientConfig, new SessionStorage(), new \Guzzle\Http\Client());
        $cb->handleCallback($_GET);

        header("HTTP/1.1 302 Found");
        header("Location: http://www.example.org/index.php");
    } catch (AuthorizeException $e) {
        // this exception is thrown by Callback when the OAuth server returns a 
        // specific error message for the client, e.g.: the user did not authorize 
        // the request
        echo sprintf("ERROR: %s, DESCRIPTION: %s", $e->getMessage(), $e->getDescription());
    } catch (\Exception $e) {
        // other error, these should never occur in the normal flow
        echo sprintf("ERROR: %s", $e->getMessage());
    }

This is all that is needed here. The authorization code will be extracted from
the callback URL and used to obtain an access token. The access token will be
stored in the token storage, here `SessionStorage` and the browser will be 
redirected back to the page where the `Api` calls are made, here `index.php`.

# Token Storage
You can store the tokens either in `SessionStorage` or `PdoStorage`. The first
one is already demonstrated above and requires no further configuration, it 
just works out of the box. 

    $tokenStorage = new SessionStorage();

The PDO backend requires you specifying the database you want to use:

    $db = new PDO("sqlite:/path/to/db/client.sqlite");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tokenStorage = new PdoStorage($db);

In both cases you can use `$tokenStorage` in the constructor where before we 
put `new SessionStorage()` there directly. See the PHP PDO documentation on how 
to specify other databases. 

Please note that if you use SQLite, please note that the *directory* you write 
the file to needs to be writable to the web server as well!