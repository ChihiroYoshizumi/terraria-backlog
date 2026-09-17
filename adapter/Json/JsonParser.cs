#nullable disable
using System;
using System.Collections.Generic;
using System.Globalization;
using System.Text;

namespace TerrariaBacklog.Adapter.Json
{
    /// <summary>
    /// 依存ライブラリを持たない最小の JSON parser。
    ///
    /// Adapter が読むのは PHP Bridge の notification-only response
    /// (<c>contracts/snapshot-response-v1.schema.json</c>) だけであり、
    /// そこに現れる object / array / string / number / bool / null のみを扱う。
    ///
    /// 結果は CLR の素の型で返す。
    /// object -&gt; <see cref="Dictionary{TKey,TValue}"/> (string -&gt; object),
    /// array -&gt; <see cref="List{T}"/> (object), string -&gt; string,
    /// number -&gt; double, bool -&gt; bool, null -&gt; null。
    /// </summary>
    public static class JsonParser
    {
        /// <summary>入力が壊れている場合は例外を投げず false を返す。</summary>
        public static bool TryParse(string json, out object value, out string error)
        {
            value = null;
            error = null;

            if (json == null)
            {
                error = "input is null.";
                return false;
            }

            try
            {
                var position = 0;
                value = ParseValue(json, ref position, 0);
                SkipWhitespace(json, ref position);

                if (position != json.Length)
                {
                    value = null;
                    error = "trailing content after the top-level JSON value.";
                    return false;
                }

                return true;
            }
            catch (FormatException ex)
            {
                value = null;
                error = ex.Message;
                return false;
            }
        }

        private const int MaxDepth = 32;

        private static object ParseValue(string s, ref int i, int depth)
        {
            if (depth > MaxDepth)
            {
                throw new FormatException("JSON nesting is too deep.");
            }

            SkipWhitespace(s, ref i);
            Require(s, i, "unexpected end of input.");

            var c = s[i];

            switch (c)
            {
                case '{':
                    return ParseObject(s, ref i, depth);
                case '[':
                    return ParseArray(s, ref i, depth);
                case '"':
                    return ParseString(s, ref i);
                case 't':
                    Expect(s, ref i, "true");
                    return true;
                case 'f':
                    Expect(s, ref i, "false");
                    return false;
                case 'n':
                    Expect(s, ref i, "null");
                    return null;
                default:
                    return ParseNumber(s, ref i);
            }
        }

        private static Dictionary<string, object> ParseObject(string s, ref int i, int depth)
        {
            i++; // '{'
            var result = new Dictionary<string, object>(StringComparer.Ordinal);

            SkipWhitespace(s, ref i);
            Require(s, i, "unterminated object.");

            if (s[i] == '}')
            {
                i++;
                return result;
            }

            while (true)
            {
                SkipWhitespace(s, ref i);
                Require(s, i, "unterminated object.");

                if (s[i] != '"')
                {
                    throw new FormatException("object member name must be a string.");
                }

                var name = ParseString(s, ref i);

                SkipWhitespace(s, ref i);
                Require(s, i, "unterminated object.");

                if (s[i] != ':')
                {
                    throw new FormatException("expected ':' after an object member name.");
                }

                i++;
                result[name] = ParseValue(s, ref i, depth + 1);

                SkipWhitespace(s, ref i);
                Require(s, i, "unterminated object.");

                if (s[i] == ',')
                {
                    i++;
                    continue;
                }

                if (s[i] == '}')
                {
                    i++;
                    return result;
                }

                throw new FormatException("expected ',' or '}' in an object.");
            }
        }

        private static List<object> ParseArray(string s, ref int i, int depth)
        {
            i++; // '['
            var result = new List<object>();

            SkipWhitespace(s, ref i);
            Require(s, i, "unterminated array.");

            if (s[i] == ']')
            {
                i++;
                return result;
            }

            while (true)
            {
                result.Add(ParseValue(s, ref i, depth + 1));

                SkipWhitespace(s, ref i);
                Require(s, i, "unterminated array.");

                if (s[i] == ',')
                {
                    i++;
                    continue;
                }

                if (s[i] == ']')
                {
                    i++;
                    return result;
                }

                throw new FormatException("expected ',' or ']' in an array.");
            }
        }

        private static string ParseString(string s, ref int i)
        {
            i++; // opening quote
            var builder = new StringBuilder();

            while (true)
            {
                Require(s, i, "unterminated string.");
                var c = s[i];

                if (c == '"')
                {
                    i++;
                    return builder.ToString();
                }

                if (c != '\\')
                {
                    builder.Append(c);
                    i++;
                    continue;
                }

                i++;
                Require(s, i, "unterminated escape sequence.");
                var escape = s[i];
                i++;

                switch (escape)
                {
                    case '"':
                        builder.Append('"');
                        break;
                    case '\\':
                        builder.Append('\\');
                        break;
                    case '/':
                        builder.Append('/');
                        break;
                    case 'b':
                        builder.Append('\b');
                        break;
                    case 'f':
                        builder.Append('\f');
                        break;
                    case 'n':
                        builder.Append('\n');
                        break;
                    case 'r':
                        builder.Append('\r');
                        break;
                    case 't':
                        builder.Append('\t');
                        break;
                    case 'u':
                        if (i + 4 > s.Length)
                        {
                            throw new FormatException("truncated \\u escape sequence.");
                        }

                        var hex = s.Substring(i, 4);
                        int code;

                        if (!int.TryParse(hex, NumberStyles.HexNumber, CultureInfo.InvariantCulture, out code))
                        {
                            throw new FormatException("invalid \\u escape sequence.");
                        }

                        builder.Append((char)code);
                        i += 4;
                        break;
                    default:
                        throw new FormatException("unsupported escape sequence.");
                }
            }
        }

        private static double ParseNumber(string s, ref int i)
        {
            var start = i;

            if (i < s.Length && (s[i] == '-' || s[i] == '+'))
            {
                i++;
            }

            while (i < s.Length && (char.IsDigit(s[i]) || s[i] == '.' || s[i] == 'e' || s[i] == 'E' || s[i] == '+' || s[i] == '-'))
            {
                i++;
            }

            if (i == start)
            {
                throw new FormatException("expected a JSON value.");
            }

            double parsed;

            if (!double.TryParse(
                    s.Substring(start, i - start),
                    NumberStyles.Float,
                    CultureInfo.InvariantCulture,
                    out parsed))
            {
                throw new FormatException("invalid number literal.");
            }

            return parsed;
        }

        private static void Expect(string s, ref int i, string literal)
        {
            if (i + literal.Length > s.Length || string.CompareOrdinal(s, i, literal, 0, literal.Length) != 0)
            {
                throw new FormatException("invalid literal.");
            }

            i += literal.Length;
        }

        private static void SkipWhitespace(string s, ref int i)
        {
            while (i < s.Length && (s[i] == ' ' || s[i] == '\t' || s[i] == '\n' || s[i] == '\r'))
            {
                i++;
            }
        }

        private static void Require(string s, int i, string message)
        {
            if (i >= s.Length)
            {
                throw new FormatException(message);
            }
        }
    }
}
