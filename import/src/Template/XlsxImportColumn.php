<?php

namespace Quansitech\Cmf\Import\Template;

use Closure;
use Filament\Actions\Imports\ImportColumn;

/**
 * 带模板层声明能力的导入列，与行级 rules() 同在 getColumns() 单一事实源。
 * textLock() 锁文本格式（numFmt `@`）防 Excel 把长数字转科学计数法丢精度；
 * length() 挂 textLength 校验（单参为精确长度）；dropdown() 挂隐藏 _options
 * 范围引用下拉。普通 ImportColumn 列无模板校验，行为不变。
 */
class XlsxImportColumn extends ImportColumn
{
    protected bool|Closure $isTextLocked = false;

    protected int|Closure|null $minLength = null;

    protected int|Closure|null $maxLength = null;

    protected ?DropdownSource $dropdownSource = null;

    public function textLock(bool|Closure $condition = true): static
    {
        $this->isTextLocked = $condition;

        return $this;
    }

    public function length(int|Closure|null $min = null, int|Closure|null $max = null): static
    {
        if (func_num_args() === 1) {
            $this->minLength = $min;
            $this->maxLength = $min;

            return $this;
        }

        $this->minLength = $min;
        $this->maxLength = $max;

        return $this;
    }

    public function dropdown(DropdownSource $source): static
    {
        $this->dropdownSource = $source;

        return $this;
    }

    public function isTextLocked(): bool
    {
        return (bool) $this->evaluate($this->isTextLocked);
    }

    public function getMinLength(): ?int
    {
        return $this->evaluate($this->minLength);
    }

    public function getMaxLength(): ?int
    {
        return $this->evaluate($this->maxLength);
    }

    public function getDropdownSource(): ?DropdownSource
    {
        return $this->dropdownSource;
    }
}
