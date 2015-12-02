CREATE FUNCTION [dbo].[LPAD](@str nvarchar(max), @len int, @padstr nvarchar(max)) RETURNS nvarchar(4000) AS
            BEGIN
	            RETURN left(@str + replicate(@padstr,@len),@len);
            END