#nullable disable
using System;
using System.Globalization;
using System.Text;

namespace TerrariaBacklog.Adapter.Json
{
    /// <summary>
    /// 依存ライブラリを持たない最小の JSON writer。
    ///
    /// TShock 4.3.13 は Newtonsoft.Json 7.0 を同梱しているが、Adapter の純ロジック層は
    /// net45 (Plugin) と net9.0 (テスト) の両方へ同じソースをコンパイルするため、
    /// バージョンの異なる外部ライブラリに依存させない。
    ///
    /// 出力は <c>contracts/snapshot-v1.schema.json</c> に適合する必要があるため、
    /// 数値・真偽値・文字列のエスケープだけを厳密に扱う最小構成にしてある。
    /// </summary>
    public sealed class JsonWriter
    {
        private readonly StringBuilder _buffer = new StringBuilder();
        private bool _needComma;

        public void StartObject()
        {
            Separator();
            _buffer.Append('{');
            _needComma = false;
        }

        public void EndObject()
        {
            _buffer.Append('}');
            _needComma = true;
        }

        public void StartArray()
        {
            Separator();
            _buffer.Append('[');
            _needComma = false;
        }

        public void EndArray()
        {
            _buffer.Append(']');
            _needComma = true;
        }

        public void Name(string name)
        {
            if (name == null)
            {
                throw new ArgumentNullException("name");
            }

            Separator();
            WriteEscaped(name);
            _buffer.Append(':');
            _needComma = false;
        }

        public void Value(string value)
        {
            Separator();

            if (value == null)
            {
                _buffer.Append("null");
            }
            else
            {
                WriteEscaped(value);
            }

            _needComma = true;
        }

        public void Value(int value)
        {
            Separator();
            _buffer.Append(value.ToString(CultureInfo.InvariantCulture));
            _needComma = true;
        }

        public void Value(bool value)
        {
            Separator();
            _buffer.Append(value ? "true" : "false");
            _needComma = true;
        }

        public override string ToString()
        {
            return _buffer.ToString();
        }

        private void Separator()
        {
            if (_needComma)
            {
                _buffer.Append(',');
            }

            _needComma = false;
        }

        private void WriteEscaped(string value)
        {
            _buffer.Append('"');

            for (var i = 0; i < value.Length; i++)
            {
                var c = value[i];

                switch (c)
                {
                    case '"':
                        _buffer.Append("\\\"");
                        break;
                    case '\\':
                        _buffer.Append("\\\\");
                        break;
                    case '\b':
                        _buffer.Append("\\b");
                        break;
                    case '\f':
                        _buffer.Append("\\f");
                        break;
                    case '\n':
                        _buffer.Append("\\n");
                        break;
                    case '\r':
                        _buffer.Append("\\r");
                        break;
                    case '\t':
                        _buffer.Append("\\t");
                        break;
                    default:
                        if (c < ' ' || c == '')
                        {
                            _buffer.Append("\\u");
                            _buffer.Append(((int)c).ToString("x4", CultureInfo.InvariantCulture));
                        }
                        else
                        {
                            _buffer.Append(c);
                        }

                        break;
                }
            }

            _buffer.Append('"');
        }
    }
}
