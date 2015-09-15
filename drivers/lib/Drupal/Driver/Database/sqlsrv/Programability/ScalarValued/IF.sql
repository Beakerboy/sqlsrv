CREATE FUNCTION [IF](@expr1 sql_variant, @expr2 sql_variant, @expr3 sql_variant) RETURNS sql_variant AS
            BEGIN
              DECLARE @result sql_variant
              SET @result = CASE WHEN CAST(@expr1 AS int) != 0 THEN @expr2 ELSE @expr3 END
              RETURN @result
            END