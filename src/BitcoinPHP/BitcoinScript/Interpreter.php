<?php

/**
 *
 *
 * @author Jan Moritz Lindemann
 */

namespace BitcoinPHP\BitcoinScript;

class Interpreter
{
    private $vfExec = array();
    private $mainStack = array();
    private $altStack = array();
    private $script;

    private $opCodes = array();

    private $rOpCodes = array();

    /**
     * @param OpCodes $opCodes
     */
    public function __construct($opCodes)
    {
        $this->opCodes = $opCodes->getOpcodes();
        $this->rOpCodes = $opCodes->getRopCodes();
    }

    /**
     * Convert a number to a compact Int
     * taken from https://github.com/scintill/php-bitcoin-signature-routines/blob/master/verifymessage.php
     *
     * @param $i
     * @return string
     * @throws \Exception
     */
    public function numToVarIntString($i)
    {
        if ($i < 0xfd) {
            return chr($i);
        } else if ($i <= 0xffff) {
            return pack('Cv', 0xfd, $i);
        } else if ($i <= 0xffffffff) {
            return pack('CV', 0xfe, $i);
        } else {
            throw new \Exception('int too large');
        }
    }

    public function varIntStringToInt($n)
    {
        if (strlen($n) == 1) // 8 bits
            return ord($n);
        elseif (strlen($n) == 2) // 16 bits
            return unpack('v', $n)[1];
        elseif (strlen($n) == 3) // 24 bits
        { // according to http://stackoverflow.com/a/11732142/2652054
            $return = unpack('ca/ab/cc', $n);
            return $return['a'] + ($return['b'] << 8) + ($return['c'] << 16);
        } elseif (strlen($n) == 4) // 32 bits
            return unpack('V', $n)[1];
        else
            throw new \Exception('number too big');
    }

    public function pushSizeStringToInt($size)
    {
        if (strlen($size) < 5)
            return $this->varIntStringToInt($size);
        else
            throw new \Exception('invalid size');
    }

    public function getOpCodes()
    {
        return $this->opCodes;
    }

    public function getrOpCodes()
    {
        return $this->rOpCodes;
    }

    public function setScript($script)
    {
        $this->script = $script;
    }

    public function setHexScript($script)
    {
        $this->script = hex2bin($script);
    }

    public function evalScript($verbose = false)
    {
        $position = 0;
        do {
            $position = $this->executeOpCode($position);
            if ($verbose) {
                echo 'Main stack: ';
                $this->dumpMainStack();
                echo "\n";
                echo 'Main altstack: ';
                $this->dumpAltStack();
            }
        } while ($position);
    }

    public function executeOpCode($position = 0)
    {
        $bnZero = 0;
        $bnOne = 1;
        $bnFalse = 0;
        $bnTrue = 1;
        $vchFalse = 0;
        $vchTrue = 1;
        $nextPosition = 0;
        $rOpCode = ord(substr($this->script, $position, 1));
        $opCode = $this->rOpCodes[$rOpCode];
        switch ($opCode) {
            case OpCodes::OP_PUSHDATA1:
            case OpCodes::OP_PUSHDATA2:
            case OpCodes::OP_PUSHDATA3:
            case OpCodes::OP_PUSHDATA4:
                $pushSizeLength = 0;

                if ($opCode == OpCodes::OP_PUSHDATA1)
                    $pushSizeLength = 1;

                if ($opCode == OpCodes::OP_PUSHDATA2)
                    $pushSizeLength = 2;

                if ($opCode == OpCodes::OP_PUSHDATA3)
                    $pushSizeLength = 3;

                if ($opCode == OpCodes::OP_PUSHDATA4)
                    $pushSizeLength = 4;

                $pushLength = $this->pushSizeStringToInt(substr($this->script, $position + 1, $pushSizeLength));
                $this->pushOnMainStack(substr($this->script, $position + 1 + $pushSizeLength, $pushLength));
                $nextPosition = $this->nextPosition($position, 1 + $pushSizeLength + $pushLength);
                break;

            case OpCodes::OP_CAT:
            case OpCodes::OP_SUBSTR:
            case OpCodes::OP_LEFT:
            case OpCodes::OP_RIGHT:
            case OpCodes::OP_INVERT:
            case OpCodes::OP_AND:
            case OpCodes::OP_OR:
            case OpCodes::OP_XOR:
            case OpCodes::OP_2MUL:
            case OpCodes::OP_2DIV:
            case OpCodes::OP_MUL:
            case OpCodes::OP_DIV:
            case OpCodes::OP_MOD:
            case OpCodes::OP_LSHIFT:
            case OpCodes::OP_RSHIFT:
                return false;

            case OpCodes::OP_1NEGATE:
            case OpCodes::OP_1:
            case OpCodes::OP_2:
            case OpCodes::OP_3:
            case OpCodes::OP_4:
            case OpCodes::OP_5:
            case OpCodes::OP_6:
            case OpCodes::OP_7:
            case OpCodes::OP_8:
            case OpCodes::OP_9:
            case OpCodes::OP_10:
            case OpCodes::OP_11:
            case OpCodes::OP_12:
            case OpCodes::OP_13:
            case OpCodes::OP_14:
            case OpCodes::OP_15:
            case OpCodes::OP_16:
                // ( -- value)
                $bn = $rOpCode - $this->opCodes['OP_1'] + 1;
                $this->pushOnMainStack(substr($this->script, $position + 1, $bn));
                $nextPosition = $this->nextPosition($position, 1 + $bn);
                break;

            case OpCodes::OP_NOP:
            case OpCodes::OP_NOP1:
            case OpCodes::OP_NOP2:
            case OpCodes::OP_NOP3:
            case OpCodes::OP_NOP4:
            case OpCodes::OP_NOP5:
            case OpCodes::OP_NOP6:
            case OpCodes::OP_NOP7:
            case OpCodes::OP_NOP8:
            case OpCodes::OP_NOP9:
            case OpCodes::OP_NOP10:
                break;

            case OpCodes::OP_IF:
            case OpCodes::OP_NOTIF:
                if (empty($this->mainStack))
                    return false;
                $vch = $this->popFromMainStack();
                $fValue = $this->castToBool($vch);
                if ($opCode == 'OP_NOTIF')
                    $fValue = !$fValue;
                array_push($this->vfExec, $fValue);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_ELSE:
                if (empty($this->vfExec))
                    return false;
                $this->vfExec[0] = !$this->vfExec[0];
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_ENDIF:
                if (empty($this->vfExec))
                    return false;
                array_pop($this->vfExec);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_VERIFY:
                if (empty($this->mainStack))
                    return false;
                if (false == $this->castToBool($this->popFromMainStack()))
                    return false;
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_RETURN:
                return false;
                break;

            case OpCodes::OP_TOALTSTACK:
                if (empty($this->mainStack))
                    return false;
                $this->pushOnAlStack($this->popFromMainStack());
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_FROMALTSTACK:
                if (empty($this->altStack))
                    return false;
                $this->pushOnMainStack($this->popFromAltStack());
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_2DROP:
                // (x1 x2 -- )
                if (count($this->mainStack) < 2)
                    return false;
                $this->popFromMainStack();
                $this->popFromMainStack();
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_2DUP:
                // (x1 x2 -- x1 x2 x1 x2)
                if (count($this->mainStack) < 2)
                    return false;
                $vch1 = $this->stacktop(-2);
                $vch2 = $this->stacktop(-1);
                $this->pushOnMainStack($vch1);
                $this->pushOnMainStack($vch2);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_3DUP:
                // (x1 x2 x3 -- x1 x2 x3 x1 x2 x3)
                if (count($this->mainStack) < 3)
                    return false;
                $vch1 = $this->stacktop(-3);
                $vch2 = $this->stacktop(-2);
                $vch3 = $this->stacktop(-1);
                $this->pushOnMainStack($vch1);
                $this->pushOnMainStack($vch2);
                $this->pushOnMainStack($vch3);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_2OVER:
                // (x1 x2 x3 x4 -- x1 x2 x3 x4 x1 x2)
                if (count($this->mainStack) < 4)
                    return false;
                $vch1 = $this->stacktop(-4);
                $vch2 = $this->stacktop(-3);
                $this->pushOnMainStack($vch1);
                $this->pushOnMainStack($vch2);
                $nextPosition = $this->nextPosition($position);
                break;


            case OpCodes::OP_2ROT:
                // (x1 x2 x3 x4 x5 x6 -- x3 x4 x5 x6 x1 x2)
                if (count($this->mainStack) < 6)
                    return false;
                $vch1 = $this->stacktop(-6);
                $vch2 = $this->stacktop(-5);

                $this->eraseFromMainStack(-6);
                $this->eraseFromMainStack(-5);
                $this->pushOnMainStack($vch1);
                $this->pushOnMainStack($vch2);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_2SWAP:
                // (x1 x2 x3 x4 -- x3 x4 x1 x2)
                if (count($this->mainStack) < 4)
                    return false;
                $vch1 = $this->stacktop(-2);
                $vch2 = $this->stacktop(-4);
                $this->setOnMainStack($vch1, -4);
                $this->setOnMainStack($vch2, -2);

                $vch1 = $this->stacktop(-1);
                $vch2 = $this->stacktop(-3);
                $this->setOnMainStack($vch1, -3);
                $this->setOnMainStack($vch2, -1);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_IFDUP:
                // (x - 0 | x x)
                if (count($this->mainStack) < 1)
                    return false;
                $vch = $this->stacktop(-1);
                if ($this->castToBool($vch))
                    $this->pushOnMainStack($vch);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_DEPTH:
                // -- stacksize
                $this->pushOnMainStack(count($this->mainStack));
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_DROP:
                // (x -- )
                if (count($this->mainStack) < 1)
                    return false;
                $this->popFromMainStack();
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_DUP:
                // (x -- x x)
                if (count($this->mainStack) < 1)
                    return false;
                $vch = $this->stacktop(-1);
                $this->pushOnMainStack($vch);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_NIP:
                // (x1 x2 -- x2)
                if (count($this->mainStack) < 2)
                    return false;
                $this->eraseFromMainStack(-2);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_OVER:
                // (x1 x2 -- x1 x2 x1)
                if (count($this->mainStack) < 2)
                    return false;
                $vch = $this->stacktop(-2);
                $this->pushOnMainStack($vch);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_PICK:
            case OpCodes::OP_ROLL:
                // (xn ... x2 x1 x0 n - xn ... x2 x1 x0 xn)
                // (xn ... x2 x1 x0 n - ... x2 x1 x0 xn)
                if (count($this->mainStack) < 2)
                    return false;
                $n = $this->stacktop(-1);
                $this->popFromMainStack();
                if ($n < 0 || $n >= count($this->mainStack))
                    return false;
                $vch = $this->stacktop(-$n - 1);
                if ($opCode == 'OP_ROLL')
                    $this->eraseFromMainStack(-$n - 1);
                $this->pushOnMainStack($vch);
                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_ROT:
                // (x1 x2 x3 -- x2 x3 x1)
                //  x2 x1 x3  after first swap
                //  x2 x3 x1  after second swap
                if (count($this->mainStack) < 3)
                    return false;

                //first swap
                $vch1 = $this->stacktop(-3);
                $vch2 = $this->stacktop(-2);
                $this->setOnMainStack($vch1, -2);
                $this->setOnMainStack($vch2, -3);

                //second swap
                //first swap
                $vch1 = $this->stacktop(-2);
                $vch2 = $this->stacktop(-1);
                $this->setOnMainStack($vch1, -1);
                $this->setOnMainStack($vch2, -2);

                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_SWAP:
                // (x1 x2 -- x2 x1)
                if (count($this->mainStack) < 2)
                    return false;

                $vch1 = $this->stacktop(-1);
                $vch2 = $this->stacktop(-2);
                $this->setOnMainStack($vch1, -2);
                $this->setOnMainStack($vch2, -1);

                $nextPosition = $this->nextPosition($position);
                break;

            case OpCodes::OP_TUCK:
                // (x1 x2 -- x2 x1 x2)
                if (count($this->mainStack) < 2)
                    return false;
                $vch = $this->stacktop(-1);
                $this->setOnMainStack($vch, -2);

                $nextPosition = $this->nextPosition($position);
                break;


            case OpCodes::OP_SIZE:
                // (in -- in size)
                if (count($this->mainStack) < 1)
                    return false;

                $size = strlen($this->stacktop(-1));
                $this->pushOnMainStack($size);

                $nextPosition = $this->nextPosition($position);
                break;


            case OpCodes::OP_EQUAL:
            case OpCodes::OP_EQUALVERIFY: //case OP_NOTEQUAL: // use OP_NUMNOTEQUAL
            {
                // (x1 x2 - bool)
                if (count($this->mainStack) < 2)
                    return false;
                $vch1 = $this->stacktop(-2);
                $vch2 = $this->stacktop(-1);
                $fEqual = ($vch1 === $vch2);
                // OP_NOTEQUAL is disabled because it would be too easy to say
                // something like n != 1 and have some wiseguy pass in 1 with extra
                // zero bytes after it (numerically, 0x01 == 0x0001 == 0x000001)
                //if (opcode == OP_NOTEQUAL)
                //    fEqual = !fEqual;
                $this->popFromMainStack();
                $this->popFromMainStack();
                $this->pushOnMainStack($fEqual ? $vchTrue : $vchFalse);
                if ($opCode === OpCodes::OP_EQUALVERIFY) {
                    if ($fEqual)
                        $this->popFromMainStack();
                    else
                        return false;
                }
            }
                break;


            //
            // Numeric
            //
            case OpCodes::OP_1ADD:
            case OpCodes::OP_1SUB:
            case OpCodes::OP_NEGATE:
            case OpCodes::OP_ABS:
            case OpCodes::OP_NOT:
            case OpCodes::OP_0NOTEQUAL: {
                // (in -- out)
                if (count($this->mainStack) < 1)
                    return false;
                $bn = $this->varIntStringToInt($this->popFromMainStack());
                switch ($opCode) {
                    case OpCodes::OP_1ADD:
                        $bn += $bnOne;
                        break;
                    case OpCodes::OP_1SUB:
                        $bn -= $bnOne;
                        break;
                    case OpCodes::OP_NEGATE:
                        $bn = -$bn;
                        break;
                    case OpCodes::OP_ABS:
                        if ($bn < $bnZero) $bn = -$bn;
                        break;
                    case OpCodes::OP_NOT:
                        $bn = (int)($bn == $bnZero);
                        break;
                    case OpCodes::OP_0NOTEQUAL:
                        $bn = (int)($bn != $bnZero);
                        break;
                    default:
                        throw new \Exception('invalid opcode');
                        break;
                }
                $this->popFromMainStack();
                $this->pushOnMainStack($this->numToVarIntString($bn));
            }
                break;

            case 'OP_ADD':
            case 'OP_SUB':
            case 'OP_BOOLAND':
            case 'OP_BOOLOR':
            case 'OP_NUMEQUAL':
            case 'OP_NUMEQUALVERIFY':
            case 'OP_NUMNOTEQUAL':
            case 'OP_LESSTHAN':
            case 'OP_GREATERTHAN':
            case 'OP_LESSTHANOREQUAL':
            case 'OP_GREATERTHANOREQUAL':
            case 'OP_MIN':
            case 'OP_MAX': {
                // (x1 x2 -- out)
                if (count($this->mainStack) < 2)
                    return false;
                $bn1 = $this->stacktop(-2);
                $bn2 = $this->stacktop(-1);
                $bn = 0;
                switch ($opCode) {
                    case OpCodes::OP_ADD:
                        $bn = $bn1 + $bn2;
                        break;

                    case OpCodes::OP_SUB:
                        $bn = $bn1 - $bn2;
                        break;

                    case OpCodes::OP_BOOLAND:
                        $bn = ($bn1 != $bnZero && $bn2 != $bnZero);
                        break;
                    case OpCodes::OP_BOOLOR:
                        $bn = ($bn1 != $bnZero || $bn2 != $bnZero);
                        break;
                    case OpCodes::OP_NUMEQUAL:
                        $bn = ($bn1 == $bn2);
                        break;
                    case OpCodes::OP_NUMEQUALVERIFY:
                        $bn = ($bn1 == $bn2);
                        break;
                    case OpCodes::OP_NUMNOTEQUAL:
                        $bn = ($bn1 != $bn2);
                        break;
                    case OpCodes::OP_LESSTHAN:
                        $bn = ($bn1 < $bn2);
                        break;
                    case OpCodes::OP_GREATERTHAN:
                        $bn = ($bn1 > $bn2);
                        break;
                    case OpCodes::OP_LESSTHANOREQUAL:
                        $bn = ($bn1 <= $bn2);
                        break;
                    case OpCodes::OP_GREATERTHANOREQUAL:
                        $bn = ($bn1 >= $bn2);
                        break;
                    case OpCodes::OP_MIN:
                        $bn = ($bn1 < $bn2 ? $bn1 : $bn2);
                        break;
                    case OpCodes::OP_MAX:
                        $bn = ($bn1 > $bn2 ? $bn1 : $bn2);
                        break;
                    default:
                        throw new \Exception('Invalid op code');
                        break;
                }
                $this->popFromMainStack();
                $this->popFromMainStack();
                $this->pushOnMainStack($this->numToVarIntString($bn));

                if ($opCode == OpCodes::OP_NUMEQUALVERIFY) {
                    if ($this->castToBool(
                        $this->varIntStringToInt($this->stacktop(-1))
                    )
                    )
                        $this->popFromMainStack();
                    else
                        return false;
                }
            }
                break;

            case OpCodes::OP_WITHIN: {
                // (x min max -- out)
                if (count($this->mainStack) < 3)
                    return false;
                $bn1 = $this->stacktop(-3);
                $bn2 = $this->stacktop(-2);
                $bn3 = $this->stacktop(-1);
                $fValue = ($bn2 <= $bn1 && $bn1 < $bn3);
                $this->popFromMainStack();
                $this->popFromMainStack();
                $this->popFromMainStack();
                $this->pushOnMainStack($fValue ? $vchTrue : $vchFalse);
            }
                break;



            //
            // Crypto
            //
            case OpCodes::OP_RIPEMD160:
            case OpCodes::OP_SHA1:
            case OpCodes::OP_SHA256:
            case OpCodes::OP_HASH160:
            case OpCodes::OP_HASH256: {
                if (count($this->mainStack) < 1)
                    return false;
                $vch = $this->stacktop(-1);
                $vchHash = '';
                if ($opCode == OpCodes::OP_RIPEMD160)
                    $vchHash = hex2bin(hash('ripemd160', $vch));
                else if ($opCode == OpCodes::OP_SHA1)
                    $vchHash = hex2bin(hash('sha1', $vch));
                else if ($opCode == OpCodes::OP_SHA256)
                    $vchHash = hex2bin(hash('sha256', $vch));
                else if ($opCode == OpCodes::OP_HASH160)
                    $vchHash = hex2bin(hash('ripemd160', hex2bin(hash('sha256', $vch))));
                else if ($opCode == OpCodes::OP_HASH256)
                    $vchHash = hex2bin(hash('sha256', hex2bin(hash('sha256', $vch))));
                $this->popFromMainStack();
                $this->pushOnMainStack($vchHash);
            }
                break;
        }
        return $nextPosition;
    }

    /**
     * @param int $position
     * @return int
     */
    public function nextPosition($position, $jump = 1)
    {
        return $position + $jump;
    }

    public function popFromMainStack()
    {
        return array_pop($this->mainStack);
    }

    public function popFromAltStack()
    {
        return array_pop($this->altStack);
    }

    public function eraseFromMainStack($pos)
    {
        unset($this->mainStack[count($this->mainStack) + $pos - 1]);
    }

    public function eraseFromAltStack($pos)
    {
        unset($this->altStack[count($this->altStack) + $pos - 1]);
    }

    public function setOnMainStack($value, $pos)
    {
        $this->mainStack[count($this->mainStack) + $pos - 1] = $value;
    }

    public function setOnAltStack($value, $pos)
    {
        $this->altStack[count($this->altStack) + $pos - 1] = $value;
    }

    public function pushOnMainStack($entry)
    {
        array_push($this->mainStack, $entry);
    }

    public function pushOnAlStack($entry)
    {
        array_push($this->altStack, $entry);
    }

    public function stacktop($pos)
    {
        return $this->mainStack[count($this->mainStack) + $pos - 1];
    }

    public function altStacktop($pos)
    {
        return $this->altStack[count($this->altStack) + $pos - 1];
    }

    public function castToBool($value)
    {
        return (bool)$value;
    }

    /**
     * @param array $stack
     */
    public function dumpStack(array $stack)
    {
        echo implode(' | ', $stack);
        echo "\n";
    }

    public function dumpMainStack()
    {
        $this->dumpStack($this->mainStack);
    }

    public function dumpAltStack()
    {
        $this->dumpStack($this->altStack);
    }

    /**
     * @return mixed
     */
    public function getMainStack()
    {
        return $this->mainStack();
    }

    /**
     * @return array
     */
    public function getAltStack()
    {
        return $this->altStack;
    }

}

?>