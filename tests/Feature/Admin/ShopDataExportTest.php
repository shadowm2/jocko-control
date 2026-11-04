<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditAction;
use App\Livewire\Shops\Data\Records;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\TestCase;

class ShopDataExportTest extends TestCase
{
    use BuildsShopMirror, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->shop = Shop::factory()->create(['name' => 'Alpha Garage', 'slug' => 'alpha']);
        $this->seedShop($this->shop);
    }

    /**
     * @param  array<string, string>  $query
     * @return array{headers: list<string|null>, rows: list<list<string|null>>, raw: string}
     */
    private function csv(string $entity, array $query = []): array
    {
        $response = $this->get(route('shops.data.export', ['shop' => $this->shop, 'entity' => $entity] + $query))->assertOk();
        $raw = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", substr($raw, 3)), fn (string $line): bool => $line !== ''));
        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines);

        return ['headers' => $rows[0], 'rows' => array_slice($rows, 1), 'raw' => $raw];
    }

    public function test_it_downloads_a_csv_that_excel_reads_as_utf8(): void
    {
        $response = $this->get(route('shops.data.export', [$this->shop, 'order']))->assertOk();

        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('alpha-order-'.now()->format('Y-m-d').'.csv', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
    }

    public function test_it_lists_every_live_record_with_references_by_name_and_enums_in_words(): void
    {
        $csv = $this->csv('order');

        $this->assertSame(__('ui.shop_data.record'), $csv['headers'][0]);
        $labels = array_column($csv['rows'], 0);
        sort($labels);
        $this->assertSame(['ORD-1', 'ORD-2', 'ORD-3', 'ORD-4'], $labels, 'ORD-5 was removed in the shop');

        $row = collect($csv['rows'])->firstWhere(0, 'ORD-1');
        $header = fn (string $field): int => array_search(__('ui.shop_data.fields.'.$field), $csv['headers'], true);
        $this->assertSame('Ali Rezaei', $row[$header('user_id')]);
        $this->assertSame(__('ui.shop_data.values.order.status.pending'), $row[$header('status')]);
    }

    public function test_it_follows_the_search_and_scope_of_the_list(): void
    {
        $this->assertSame([['ORD-3']], array_map(fn (array $row): array => [$row[0]], $this->csv('order', ['search' => 'ORD-3'])['rows']));
        $this->assertSame(['ORD-5'], array_column($this->csv('order', ['scope' => 'removed'])['rows'], 0));
        $this->assertCount(5, $this->csv('order', ['scope' => 'all'])['rows']);
    }

    public function test_a_cell_can_never_start_a_formula(): void
    {
        $this->mirror($this->shop, 'user', 90, ['type' => 'customer', 'first_name' => '=HYPERLINK("http://x")', 'last_name' => 'Evil', 'mobile' => '09120000090'], '=HYPERLINK("http://x") Evil');

        $labels = array_column($this->csv('user', ['search' => 'Evil'])['rows'], 0);

        $this->assertSame(['\'=HYPERLINK("http://x") Evil'], $labels);
    }

    public function test_it_only_exports_the_shop_asked_for(): void
    {
        $other = Shop::factory()->create(['slug' => 'beta']);
        $this->mirror($other, 'user', 7, ['type' => 'customer', 'first_name' => 'Zed', 'last_name' => 'Other', 'mobile' => '09120000007'], 'Zed Other');

        $this->assertStringNotContainsString('Zed Other', $this->csv('user')['raw']);
    }

    public function test_it_is_written_to_the_audit_trail(): void
    {
        $this->csv('order', ['search' => 'ORD-1']);

        $log = AuditLog::query()->where('action', AuditAction::ShopDataExported)->sole();
        $this->assertSame($this->shop->getKey(), $log->shop_id);
        $this->assertEquals(['entity' => 'order', 'search' => 'ORD-1', 'scope' => 'alive'], $log->metadata);
    }

    public function test_an_unknown_entity_is_not_found_and_guests_are_sent_to_the_login(): void
    {
        $this->get(route('shops.data.export', [$this->shop, 'nonsense']))->assertNotFound();

        auth()->logout();
        $this->get(route('shops.data.export', [$this->shop, 'order']))->assertRedirect(route('login'));
    }

    public function test_the_list_links_to_the_export_with_its_filters(): void
    {
        Livewire::test(Records::class, ['shop' => $this->shop, 'entity' => 'order'])
            ->set('search', 'ORD-1')
            ->set('scope', 'all')
            ->assertSeeHtml('data-export-csv')
            ->assertSeeHtml(e(route('shops.data.export', ['shop' => $this->shop, 'entity' => 'order', 'search' => 'ORD-1', 'scope' => 'all'])));
    }
}
