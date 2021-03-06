<?php 
/**
 * convert c function call to actual embedded c code and c function invoking code in zephir 
 * c function call format:
 * result = call_c_function([c source file], [c function name], [input var 1],[input var 2],...);
 */
namespace PHPtoCExt\Converter;

class CFunctionCallConverter extends \PHPtoCExt\Converter
{
  public function convert()
  {
    //first, find out all call_c_function calls and find out the corresponding class context this c function call belongs to
    $classMap = $this->getClassMap();

    $cSourceCodeMap = array();

    foreach($classMap as $className => $classInfo) {
      $originalClassCode = implode("\n",array_slice($this->codeLines, $classInfo->startLine - 1, $classInfo->endLine - $classInfo->startLine + 1)); 
      foreach($classInfo->methodInfos as $methodPureName => $methodInfo) {
        $tmpCFuncCallResultDefined = false;
        for ($index = $methodInfo->startLine; $index <= $methodInfo->endLine; $index++) {
          if (preg_match("/call_c_function\(.*\)/", $this->codeLines[$index], $matches)) {

            if(count($matches) == 1) {
              //we simply add $tmpCFuncCallResult = null;\n at the beginning of the method 
              $codeLine = $this->codeLines[$index]; //store the original code line here
              if (!$tmpCFuncCallResultDefined) {
                $this->codeLines[$index] = "\$tmpCFuncCallResult = null;\n".$this->codeLines[$index];
                $tmpCFuncCallResultDefined = true;
              } 

              $codeLine = str_replace(array("call_c_function","(",")",";","$"),"",trim($codeLine));
              $lineComps = explode("=",$codeLine);
              $lineCompsCount = count($lineComps);
              $resultVarName = "";
              if ($lineCompsCount == 1) { //this means we just call the c function and do not have a return variable
                $cFunctionCallComps = explode(",",$lineComps[0]);
                foreach($cFunctionCallComps as $idx => $comp) {
                  $cFunctionCallComps[$idx] = trim(str_replace("$","",$cFunctionCallComps[$idx]));
                }
              } else if ($lineCompsCount > 1) { //this means we call the c function and then store the result in a return variable 
                $resultVarName = trim(str_replace("$","",$lineComps[0]));
                $cFunctionCallComps = explode(",",$lineComps[1]);
                foreach($cFunctionCallComps as $idx => $comp) {
                  $cFunctionCallComps[$idx] = trim(str_replace("$","",$cFunctionCallComps[$idx]));
                }
              }

              if (count($cFunctionCallComps) > 0) {
                $firstComp = array_shift($cFunctionCallComps);
                $cSourceFile = str_replace(array("'",'"'),"",$firstComp);
                $secondComp = array_shift($cFunctionCallComps);
                //get the called c function name 
                $cFUnctionName = str_replace(array("'",'"'),"",$secondComp);
                //prepend c file name on each called c function 
                //we need to consider that the c file name might have / in it, and we need to change that to _ 
                $cSourceFilePureName = str_replace("/","_",$cSourceFile);
                $cFUnctionName = explode(".",$cSourceFilePureName)[0]."_".$cFUnctionName;
                $cFUnctionInputParamsStr = "";
                if (count($cFunctionCallComps) > 0) {
                  $cFUnctionInputParamsStr = implode(", ",$cFunctionCallComps); 
                }

                $cFunctionCallCode = "";
                if (strlen($resultVarName) == 0) {
                  $cFunctionCallCode .= "\n%{\n";
                  $cFunctionCallCode .= $cFUnctionName."($cFUnctionInputParamsStr);";                 
                  $cFunctionCallCode .=  "\n}%\n";
                  if (strlen($cFUnctionInputParamsStr) == 0) { 
                    $expectedZephirCode = 'call_c_function('.$firstComp.', '.$secondComp.');';
                  } else {
                    $expectedZephirCode = 'call_c_function('.$firstComp.', '.$secondComp.', '.implode(", ",$cFunctionCallComps).');';
                  }
                } else {
                  $cFunctionCallCode .= "let $resultVarName = null;\n"; //initialize result var
                  $cFunctionCallCode .= "\n%{\n";
                  $cFunctionCallCode .= "tmpCFuncCallResult = ".$cFUnctionName."($cFUnctionInputParamsStr);\n";
                  $cFunctionCallCode .=  "\n}%\n";
                  $cFunctionCallCode .= "let $resultVarName = tmpCFuncCallResult;\n";
                  if (strlen($cFUnctionInputParamsStr) == 0) {
                    $expectedZephirCode = 'let '.$resultVarName.' =  call_c_function('.$firstComp.', '.$secondComp.');';
                  } else {
                    $expectedZephirCode = 'let '.$resultVarName.' =  call_c_function('.$firstComp.', '.$secondComp.', '.implode(", ",$cFunctionCallComps).');';
                  }
                }

                $this->postSearchAndReplace($expectedZephirCode,$cFunctionCallCode);

                //now, inject the c source code to the top of the class 
                $namespace = $classInfo->namespace;
                $classPureName = array_pop(explode("\\",$className));
                $originalCode = "namespace $namespace;\n\n"."class $classPureName\n";
                //make sure we only have one unique copy of c source file per class
                $classNameCSourceFileKey = $className.".".$cSourceFile;
                if (!isset($cSourceCodeMap[$classNameCSourceFileKey])) {
                  //read the c source file content
                  $cSourceCode = file_get_contents($this->inputDir."/".$cSourceFile);

                  //now prefix the c source file's pure name to each function and corresponding function calls 
                  //this way, we will be sure that we will not have the same function name defined in more than one c source file
                  $cSourceCode = $this->prefixFunctionsWithCSourceFileName($cSourceFile, $cSourceCode);             
              
                  $cSourceCodeMap[$classNameCSourceFileKey] = $cSourceCode;
                  $withCSourceCode = "%{\n".$cSourceCodeMap[$classNameCSourceFileKey]."\n}%\n".$originalCode; 
                  $this->postSearchAndReplace($originalCode, $withCSourceCode);
                }
              }

            }

          }
        }

      }

      $currentClassCode = implode("\n",array_slice($this->codeLines, $classInfo->startLine - 1, $classInfo->endLine - $classInfo->startLine + 1)); 
      $this->searchAndReplace($originalClassCode, $currentClassCode);
    }
  }

  private function prefixFunctionsWithCSourceFileName($cSourceFile, $cSourceCode)
  {
    //prepend file name on each defined c functions 
    $cFunctionCallsToSearch = array();
    $cFunctionCallsToReplace = array();
    //we need to consider that cSourceFile might have / in it, in that case we need to change / to _
    $prefix = explode(".",str_replace("/","_",$cSourceFile))[0];
    $cSourceCode = preg_replace_callback("|[a-zA-Z0-9_]+[\s]*\(.*\)([\s]*){|",function($matches) use (&$prefix,&$cFunctionCallsToSearch, &$cFunctionCallsToReplace) {
      if (count($matches) > 0 && strlen($matches[0]) > 0) {
        //tricky, need to make sure it is not if, for and while 
        $functionName = trim(substr($matches[0], 0, strpos($matches[0],"(")));
        if ( $functionName !== "for" && $functionName !== "while" && $functionName!== "if" ) {
          $cFunctionCallsToSearch[] = $functionName."(";
          $cFunctionCallsToReplace[] = $prefix."_".$functionName."(";
          return $prefix."_".$matches[0];
        } else {
          return $matches[0];
        }
      }
    },$cSourceCode);

    //now also change all subsequent function calls in context 
    $cSourceCode = str_replace($cFunctionCallsToSearch, $cFunctionCallsToReplace, $cSourceCode);

    //now this will result in the pattern {prefix}_{prefix}_, we need to change that to {prefix}_
    $cSourceCode = str_replace(" {$prefix}_{$prefix}_"," {$prefix}_", $cSourceCode); 

    return $cSourceCode;
  }
}
