<?php

namespace App\Livewire\Plans;

use App\Enums\AuditAction;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\Plan;
use App\Services\AuditLogger;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The plans a shop can be put on. They are informational: a shop is never
 * blocked for its plan, only shown as paid, due soon or expired.
 */
class Index extends Component
{
    use AuthorizesAdmin;

    #[Locked]
    public ?int $planId = null;

    public string $name = '';

    public string $price = '0';

    public string $billing_days = '30';

    public string $max_devices = '';

    public bool $is_active = true;

    public string $notes = '';

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('plan-form')->show();
    }

    public function edit(int $id): void
    {
        $plan = Plan::query()->findOrFail($id);

        $this->resetForm();
        $this->planId = $plan->id;
        $this->name = $plan->name;
        $this->price = (string) $plan->price;
        $this->billing_days = (string) $plan->billing_days;
        $this->max_devices = $plan->max_devices === null ? '' : (string) $plan->max_devices;
        $this->is_active = $plan->is_active;
        $this->notes = (string) $plan->notes;

        Flux::modal('plan-form')->show();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('plans', 'name')->ignore($this->planId)],
            'price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'billing_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'max_devices' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function save(AuditLogger $audit): void
    {
        $data = $this->validate();
        $data['max_devices'] = $data['max_devices'] === '' || $data['max_devices'] === null ? null : (int) $data['max_devices'];
        $data['notes'] = $data['notes'] === '' ? null : $data['notes'];

        if ($this->planId === null) {
            $plan = Plan::create($data);
            $audit->record(AuditAction::PlanCreated, auth()->user(), metadata: ['name' => $plan->name]);
        } else {
            $plan = Plan::query()->findOrFail($this->planId)->fill($data);
            $changed = array_keys($plan->getDirty());
            $plan->save();

            if ($changed !== []) {
                $audit->record(AuditAction::PlanUpdated, auth()->user(), metadata: ['name' => $plan->name, 'changed' => $changed]);
            }
        }

        Flux::modal('plan-form')->close();
        Flux::toast(text: __('ui.plans.saved'), variant: 'success');
        $this->resetForm();
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $plan = Plan::query()->withCount('shops')->findOrFail($id);

        if ($plan->shops_count > 0) {
            Flux::toast(text: __('ui.plans.in_use'), variant: 'danger');

            return;
        }

        $plan->delete();
        $audit->record(AuditAction::PlanDeleted, auth()->user(), metadata: ['name' => $plan->name]);
        Flux::toast(text: __('ui.plans.deleted'), variant: 'success');
    }

    private function resetForm(): void
    {
        $this->reset(['planId', 'name', 'price', 'billing_days', 'max_devices', 'is_active', 'notes']);
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.plans.index', [
            'plans' => Plan::query()->withCount('shops')->orderByDesc('is_active')->orderBy('price')->orderBy('id')->get(),
        ])->layout('components.layouts.app', ['title' => __('ui.plans.title')]);
    }
}
