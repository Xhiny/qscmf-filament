<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Tests\Fixtures\Forms;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;
use Quansitech\Cmf\Media\Filament\Forms\Components\MediaPicker;

/**
 * MediaPicker 渲染测试用的最小表单组件。
 */
class MediaPickerForm extends Component implements HasForms
{
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->components([
                MediaPicker::make('photos')->multiple()->uploadRule('img-10'),
                MediaPicker::make('legacy'),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>{{ $this->form }}</div>
        BLADE;
    }
}
