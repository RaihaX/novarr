<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * One item of the phone apps' notification feed (GET /api/v1/notifications,
 * App\Services\Requests\RequestFeed): addressed to one user, or to every
 * admin when user_id is null.
 */
class RequestNotification extends Model
{
    public const KIND_NEW = 'request_new';
    public const KIND_APPROVED = 'request_approved';
    public const KIND_DECLINED = 'request_declined';
    public const KIND_AVAILABLE = 'request_available';

    public const UPDATED_AT = null;

    protected $table = 'request_notifications';

    protected $guarded = ['id'];
}
