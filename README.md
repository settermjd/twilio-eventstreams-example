# Twilio Event Streams PHP Example

This is a small Slim RESTful API showing how to use the Twilio Event Streams webhook in PHP.

## Overview

The application provides routes, primarily, to create a webhook sink where events will be delivered, subscribe to one or more Twilio events.
It also has routes to list all existing sinks, and to delete sinks.

## Prerequisites/Requirements

To run the code, you will need the following:

- PHP 8.3
- [Composer][composer_url] installed globally
- A network testing tool such as [curl][curl_url] or [Postman][postman_url]
- [ngrok][ngrok_url] and a free ngrok account
- A Twilio account (free or paid) with an active phone number that can send SMS.
  If you are new to Twilio, [create a free account][try_twilio_url].

[composer_url]: https://getcomposer.org
[ngrok_url]: https://ngrok.com/
[try_twilio_url]: https://www.twilio.com/try-twilio
[curl_url]: https://curl.se/
[postman_url]: https://www.postman.com/