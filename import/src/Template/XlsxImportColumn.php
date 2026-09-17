<?php

namespace Quansitech\Cmf\Import\Template;

use Closure;
use Filament\Actions\Imports\ImportColumn;

/**
 * 带模板层声明能力的导入列：与行级 rules() 同在 getColumns() 单一事实源。
 *
 * - textLock()：模板单元格锁定为文本格式（numFmt `@`），防 Excel 将长数字
 *   （身份证号等）转为科学计数法造成不可逆精度丢失
 * - length(min, max)：模板 textLength 单元格校验；单参为精确长度
 * - dropdown(DropdownSource)：模板下拉选项（隐藏 _options sheet 范围引用）
 *
 * 普通 ImportColumn 列无模板校验，行为不变。
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

    /**
     * length($max) / length($exact) 为精确长度，length($min, $max) 为区间。
     */
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
