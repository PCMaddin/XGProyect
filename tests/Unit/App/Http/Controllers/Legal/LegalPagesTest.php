<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Legal;

use App\Http\Controllers\Legal\LegalController;
use App\Http\Controllers\Operator\LegalController as OperatorLegalController;
use App\Models\Operator;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Models\User;
use App\Services\Legal\LegalPages;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The legal pages: written by the operators on /operator (Markdown, one text
 * per language), shown to everyone under /legal/{page}, linked only once
 * they have a text. HTML in a text never reaches the page as HTML.
 */
#[CoversClass(LegalController::class)]
#[CoversClass(OperatorLegalController::class)]
#[CoversClass(LegalPages::class)]
class LegalPagesTest extends DatabaseTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'operators.web' => true, 'operators.allowed_ips' => '']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        Cache::flush();
    }

    public function testAPageWithoutATextDoesNotExistAndIsNotLinked(): void
    {
        $this->get('/legal/imprint')->assertNotFound();
        // like any unknown path
        $this->get('/legal/anything')->assertRedirect();
        $this->assertSame([], app(LegalPages::class)->written());
        $this->get('/')->assertOk()->assertDontSee('/legal/');
    }

    public function testTheOperatorWritesATextAndEveryoneReadsIt(): void
    {
        $this->signIn();

        $this->get('/operator/legal')->assertOk()->assertSee(__('operator/operator.op_legal_missing'));
        $this->post('/operator/legal/imprint/de', ['body' => "# Impressum\r\n\nMax Muster, **Musterstraße 1**\n\n[Kontakt](mailto:info@example.com)", 'action' => 'save'])
            ->assertRedirect(route('operator.legal.edit', ['page' => 'imprint', 'locale' => 'de']))
            ->assertSessionHas('status', __('operator/operator.op_legal_saved'));

        $this->get('/legal/imprint?lang=de')->assertOk()
            ->assertSee('<h1>Impressum</h1>', false)
            ->assertSee('<strong>Musterstraße 1</strong>', false)
            ->assertSee('href="mailto:info@example.com"', false)
            ->assertSee(__('home/legal.lg_imprint', [], 'de'));

        // linked from the start page; the terms have no text yet
        $this->get('/')->assertOk()->assertSee('/legal/imprint', false)->assertDontSee('/legal/terms', false);

        $event = DB::connection('central')->table('operator_events')->where('action', 'legal_changed')->sole();
        $this->assertSame('imprint/de', $event->subject);
        $this->assertSame('ops@example.com', DB::connection('central')->table('legal_pages')->value('updated_by'));
    }

    public function testTheAccountAndPasswordPagesLinkTheImprintToo(): void
    {
        app(LegalPages::class)->save('imprint', 'en', '# Imprint', 'ops@example.com', 100);
        $player = new User(['name' => 'Reader', 'email' => 'reader@example.com', 'password' => 'secret-password', 'home_planet_id' => 1, 'current_planet' => 1]);
        $player->save();

        $this->get('/account/recover')->assertOk()->assertSee('/legal/imprint', false);
        $this->actingAs($player)->get('/account/report')->assertOk()->assertSee('/legal/imprint', false);
        $this->actingAs($player)->get('/account/privacy')->assertOk()->assertSee('/legal/imprint', false);
    }

    public function testAVisitorInAnotherLanguageSeesTheTextWrittenFirst(): void
    {
        $pages = app(LegalPages::class);
        $pages->save('terms', 'de', 'Deutsche AGB', 'ops@example.com', 100);
        $pages->save('terms', 'fr', 'Conditions françaises', 'ops@example.com', 200);

        $this->get('/legal/terms?lang=fr')->assertOk()->assertSee('Conditions françaises')->assertSee('lang="fr"', false);
        $this->get('/legal/terms?lang=en')->assertOk()->assertSee('Deutsche AGB')->assertSee('lang="de"', false);
        // without ?lang the browser's language
        $this->get('/legal/terms', ['Accept-Language' => 'fr-FR,fr;q=0.9'])->assertOk()->assertSee('Conditions françaises');
    }

    public function testHtmlAndScriptLinksInATextStayText(): void
    {
        app(LegalPages::class)->save('privacy', 'en', "<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n\n[click](javascript:alert(3))", 'ops@example.com', 100);

        $response = $this->get('/legal/privacy?lang=en')->assertOk();
        $response->assertDontSee('<script>alert(1)', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertDontSee('<img', false)->assertDontSee('href="javascript:', false);
    }

    public function testAPreviewIsNotSavedAndAnEmptyTextRemovesThePage(): void
    {
        $this->signIn();
        app(LegalPages::class)->save('withdrawal', 'en', 'Old text', 'ops@example.com', 100);

        $this->post('/operator/legal/withdrawal/en', ['body' => '## New text', 'action' => 'preview'])
            ->assertRedirect(route('operator.legal.edit', ['page' => 'withdrawal', 'locale' => 'en', 'preview' => 1]));
        $this->get(route('operator.legal.edit', ['page' => 'withdrawal', 'locale' => 'en', 'preview' => 1]))
            ->assertOk()->assertSee('<h2>New text</h2>', false)->assertSee(__('operator/operator.op_legal_unsaved'));
        $this->get('/legal/withdrawal?lang=en')->assertSee('Old text');

        $this->post('/operator/legal/withdrawal/en', ['body' => '  ', 'action' => 'save'])
            ->assertSessionHas('status', __('operator/operator.op_legal_removed'));
        $this->get('/legal/withdrawal?lang=en')->assertNotFound();
    }

    public function testOnlyASignedInOperatorWritesTexts(): void
    {
        $this->get('/operator/legal')->assertRedirect(route('operator.login'));
        $this->post('/operator/legal/imprint/de', ['body' => 'Hijacked'])->assertRedirect(route('operator.login'));
        $this->assertSame(0, DB::connection('central')->table('legal_pages')->count());

        $this->signIn();
        $this->get('/operator/legal/imprint/xx')->assertNotFound();
        $this->post('/operator/legal/imprint/de', ['body' => str_repeat('a', LegalPages::MAX_LENGTH + 1)])->assertSessionHasErrors('body');
    }

    private function signIn(): void
    {
        $totp = new Totp();
        $operator = new Operator(['email' => 'ops@example.com', 'password' => 'correct horse battery', 'created_at' => time()]);
        app(TwoFactor::class)->enable($operator, self::SECRET, $totp->code(self::SECRET, $totp->step(time() - 60)), time() - 60);

        $this->post('/operator/login', ['email' => 'ops@example.com', 'password' => 'correct horse battery']);
        $this->post('/operator/code', ['code' => $totp->code(self::SECRET, $totp->step(time()))])->assertRedirect(route('operator.universes'));
    }
}
