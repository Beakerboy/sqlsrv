using System;
using Microsoft.SqlServer.Server;
using System.Text.RegularExpressions;
     
public partial class RegExCompiled
{
  [SqlFunction(IsDeterministic = true, IsPrecise = true)]
  public static bool RegExCompiledMatch(string pattern, string matchString)
  {
    return (int)Regex.Match(matchString.TrimEnd(null), pattern.TrimEnd(null), RegexOptions.Compiled).Success;
  }
};
