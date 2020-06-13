CREATE FUNCTION [SUBSTRING_INDEX](@string varchar(8000), @delimiter char(1), @count int) RETURNS varchar(8000) AS
            BEGIN
              DECLARE @result varchar(8000)
              DECLARE @end int
              DECLARE @part int
              SET @end = 0
              SET @part = 0
              IF (@count = 0)
              BEGIN
                SET @result = ''
              END
              ELSE
              BEGIN
                IF (@count < 0)
                BEGIN
                  SET @string = REVERSE(@string)
                END
                WHILE (@part < ABS(@count))
                BEGIN
                  SET @end = CHARINDEX(@delimiter, @string, @end + 1)
                  IF (@end = 0)
                  BEGIN
                    SET @end = LEN(@string) + 1
                    BREAK
                  END
                  SET @part = @part + 1
                END
                SET @result = SUBSTRING(@string, 1, @end - 1)
                IF (@count < 0)
                BEGIN
                  SET @result = REVERSE(@result)
                END
              END
              RETURN @result
            END