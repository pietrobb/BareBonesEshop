<?php
/**
 * The shop cannot verify a carrier webhook because of its own configuration (missing or weak
 * secret). Answered as a server-side failure and raised as an incident, never as the sender's
 * "invalid signature".
 */
class ShippingWebhookConfigurationError extends RuntimeException
{
}
