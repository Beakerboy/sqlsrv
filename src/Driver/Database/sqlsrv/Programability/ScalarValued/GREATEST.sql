CREATE FUNCTION [GREATEST](@op1 sql_variant, @op2 sql_variant) RETURNS sql_variant AS
            BEGIN
              DECLARE @result sql_variant
              SET @result = CASE WHEN @op1 >= @op2 THEN @op1 ELSE @op2 END
              RETURN @result
            END