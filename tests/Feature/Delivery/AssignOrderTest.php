<?php

namespace Tests\Feature\Delivery;

use App\Enums\Delivery\CourierStatus;
use App\Enums\Delivery\DeliveryOrderStatus;
use App\Models\Delivery\Courier;
use App\Models\Delivery\Customer;
use App\Models\Delivery\DeliveryOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AssignOrderTest extends DeliveryTestCase
{
    public function test_it_assigns_the_nearest_available_courier(): void
    {
        $this->courierAt(self::LAT + 0.02, self::LNG);
        $nearest = $this->courierAt(self::LAT + 0.004, self::LNG);
        $this->courierAt(self::LAT + 0.001, self::LNG, CourierStatus::Offline);
        $order = $this->pendingOrderAt(self::LAT, self::LNG);

        $this->postJson(self::API."/orders/{$order->id}/assign")
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.courier_id', $nearest->id)
            ->assertJsonPath('data.courier.status', 'busy');

        $this->assertSame(CourierStatus::Busy, $nearest->fresh()->status);
        $this->assertDatabaseHas('delivery_order_status_history', [
            'order_id' => $order->id, 'old_status' => 'pending', 'new_status' => 'assigned',
        ]);
    }

    public function test_it_returns_409_when_no_courier_is_within_radius(): void
    {
        $this->courierAt(self::LAT + 0.1, self::LNG); // ~11 km away
        $order = $this->pendingOrderAt(self::LAT, self::LNG);

        $this->postJson(self::API."/orders/{$order->id}/assign")
            ->assertStatus(409)
            ->assertJsonPath('error', 'no_available_courier');

        $this->assertSame(DeliveryOrderStatus::Pending, $order->fresh()->status);
    }

    public function test_an_already_assigned_order_cannot_be_assigned_again(): void
    {
        $this->courierAt(self::LAT + 0.001, self::LNG);
        $this->courierAt(self::LAT + 0.002, self::LNG);
        $order = $this->pendingOrderAt(self::LAT, self::LNG);

        $this->postJson(self::API."/orders/{$order->id}/assign")->assertOk();
        $this->postJson(self::API."/orders/{$order->id}/assign")
            ->assertStatus(409)
            ->assertJsonPath('error', 'invalid_status_transition');

        $this->assertSame(1, Courier::query()->where('status', 'busy')->count());
    }

    public function test_a_courier_is_never_given_two_active_orders(): void
    {
        $only = $this->courierAt(self::LAT + 0.001, self::LNG);
        $first = $this->pendingOrderAt(self::LAT, self::LNG);
        $second = $this->pendingOrderAt(self::LAT, self::LNG);

        $this->postJson(self::API."/orders/{$first->id}/assign")->assertOk()->assertJsonPath('data.courier_id', $only->id);
        $this->postJson(self::API."/orders/{$second->id}/assign")->assertStatus(409);
    }

    public function test_database_rejects_double_assignment_even_if_application_checks_are_bypassed(): void
    {
        $courier = $this->courierAt(self::LAT, self::LNG);
        [$a, $b] = [$this->pendingOrderAt(self::LAT, self::LNG), $this->pendingOrderAt(self::LAT, self::LNG)];

        DB::update("UPDATE delivery_orders SET status = 'assigned', courier_id = ? WHERE id = ?", [$courier->id, $a->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::update("UPDATE delivery_orders SET status = 'assigned', courier_id = ? WHERE id = ?", [$courier->id, $b->id]);
    }

    public function test_delivering_an_order_frees_the_courier(): void
    {
        $courier = $this->courierAt(self::LAT + 0.001, self::LNG);
        // Created through the API (not the factory) so the initial history row exists.
        $order = (object) $this->postJson(self::API.'/orders', [
            'customer_id' => Customer::factory()->create()->id,
            'pickup'      => ['lat' => self::LAT, 'lng' => self::LNG],
            'delivery'    => ['lat' => self::LAT + 0.01, 'lng' => self::LNG],
        ])->assertCreated()->json('data');
        $this->postJson(self::API."/orders/{$order->id}/assign")->assertOk();

        $this->patchJson(self::API."/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->patchJson(self::API."/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->assertSame(CourierStatus::Available, $courier->fresh()->status);
        $this->assertSame(
            ['pending', 'assigned', 'picked_up', 'delivered'],
            DB::table('delivery_order_status_history')->where('order_id', $order->id)->orderBy('id')->pluck('new_status')->all(),
        );
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $order = $this->pendingOrderAt(self::LAT, self::LNG);

        $this->patchJson(self::API."/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'invalid_status_transition');

        $this->patchJson(self::API."/orders/{$order->id}/status", ['status' => 'assigned'])
            ->assertUnprocessable(); // must go through /assign

        $this->assertSame(DeliveryOrderStatus::Pending, DeliveryOrder::find($order->id)->status);
    }
}
