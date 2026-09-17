<?php

namespace Quansitech\Cmf\Import\Xlsx;

use RuntimeException;

/**
 * 伪 xlsx / 结构损坏文件的业务友好异常（整单拒绝）。
 */
class InvalidXlsxFileException extends RuntimeException {}
