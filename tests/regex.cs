using System;
using Microsoft.SqlServer.Server;
using System.Text.RegularExpressions;
     
public partial class RegExCompiled
{
  [SqlFunction(IsDeterministic = true, IsPrecise = true)]
  public static int RegExCompiledMatch(string pattern, string matchString)
  {
    if (Regex.Match(matchString.TrimEnd(null), pattern.TrimEnd(null), RegexOptions.Compiled).Success)
    {
         return 1;
    }
    else
    {
         return 0;
    }
  }
};
