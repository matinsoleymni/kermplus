<?php

namespace App\Http\Controllers\Api\Bot;

use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bot\SendEventRequest;
use App\Http\Resources\DispatchedEventResource;
use App\Models\Device;
use App\Services\EventDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private readonly EventDispatcher $dispatcher) {}

    /**
     * List the events this owner has dispatched, most recent first.
     */
    public function index(Request $request): JsonResponse
    {
        $events = $request->user()
            ->dispatchedEvents()
            ->withCount(['deliveries as acknowledged_count' => function ($query) {
                $query->where('status', DeliveryStatus::Acknowledged);
            }])
            ->latest()
            ->paginate(20);

        return DispatchedEventResource::collection($events)->response();
    }

    /**
     * Show a single dispatched event with its current acknowledgement progress.
     */
    public function show(Request $request, int $event): JsonResponse
    {
        $dispatched = $request->user()
            ->dispatchedEvents()
            ->withCount(['deliveries as acknowledged_count' => function ($query) {
                $query->where('status', DeliveryStatus::Acknowledged);
            }])
            ->findOrFail($event);

        return DispatchedEventResource::make($dispatched)->response();
    }

    /**
     * Push an event to the owner's devices via FCM.
     *
     * Targets a single device when device_id is given, otherwise broadcasts to
     * every device registered under this owner.
     */
    public function store(SendEventRequest $request): JsonResponse
    {
        $owner = $request->user();

        $device = null;
        if ($request->filled('device_id')) {
            /** @var Device $device */
            $device = $owner->devices()->findOrFail($request->integer('device_id'));
        }

        $event = $this->dispatcher->dispatch(
            owner: $owner,
            event: $request->string('event')->toString(),
            data: $request->input('data'),
            device: $device,
        );

        return DispatchedEventResource::make($event)
            ->response()
            ->setStatusCode(201);
    }
}
