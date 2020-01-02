using System;
using Microsoft.SqlServer.Server;
using System.Text.RegularExpressions;
     
public partial class RegExCompiled
{
  [SqlFunction(IsDeterministic = true, IsPrecise = true)]
  public static bool RegExCompiledMatch(string pattern, string matchString)
  {
    return Regex.IsMatch(matchString.TrimEnd(null), pattern.TrimEnd(null), RegexOptions.Compiled);
  }
};
