<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Http;

enum ApiErrorCode: string
{
    case Validation = 'VALIDATION_ERROR';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case EndpointNotFound = 'ENDPOINT_NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
    case InternalServerError = 'INTERNAL_SERVER_ERROR';
}
