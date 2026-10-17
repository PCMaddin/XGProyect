<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\AllianceController;
use App\Models\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use ReflectionMethod;
use Tests\DatabaseTestCase;

/**
 * The page where an alliance administrator chooses the alliance class.
 */
#[CoversClass(AllianceController::class)]
class AllianceClassPageTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Options::insert(['name' => 'class_change_price', 'value' => '500000', 'type' => 'int']);
        DB::table('alliance')->insert(['alliance_id' => 3, 'alliance_name' => 'Allies', 'alliance_tag' => 'ALL', 'alliance_owner' => 7]);
        DB::table('premium')->insert(['premium_user_id' => 7, 'premium_dark_matter' => 1000]);
    }

    public function testThePageOffersTheThreeClasses(): void
    {
        $view = $this->section(Request::create('game.php?page=alliance&mode=admin&edit=class'));

        $this->assertInstanceOf(View::class, $view);
        $data = $view->getData();
        $this->assertSame([1, 2, 3], array_column($data['classes'], 'value'));
        $this->assertSame(0, $data['current']);
        $this->assertSame(__('game/alliance.al_class_first_free'), $data['price']);
        $this->assertSame('alliance.admin.class', $view->name());
    }

    public function testTheAdministratorChoosesTheClassOfTheirOwnAlliance(): void
    {
        // a foreign alliance id in the request is ignored
        $response = $this->section(Request::create('game.php?page=alliance&mode=admin&edit=class&allyid=9', 'POST', ['class' => 3]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(3, $this->allianceClass());

        // a change costs dark matter the administrator does not have
        $view = $this->section(Request::create('game.php?page=alliance&mode=admin&edit=class', 'POST', ['class' => 1]));
        $this->assertInstanceOf(View::class, $view);
        $this->assertSame(__('game/alliance.al_class_no_enough_dark_matter'), $view->getData()['message']);
        $this->assertSame(3, $this->allianceClass());
    }

    private function allianceClass(): int
    {
        $value = DB::table('alliance')->where('alliance_id', 3)->value('alliance_class');
        $this->assertIsNumeric($value);

        return (int) $value;
    }

    private function section(Request $request): View | RedirectResponse
    {
        $controller = app(AllianceController::class);
        (new ReflectionClass($controller))->getProperty('user')->setValue($controller, ['id' => 7, 'ally_id' => 3]);

        $result = (new ReflectionMethod(AllianceController::class, 'adminClassSection'))->invoke($controller, $request);
        $this->assertTrue($result instanceof View || $result instanceof RedirectResponse);

        return $result;
    }
}
